<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cached reads that cannot fail the request (Redis Cluster add-on).
 *
 * The cache is an optimisation, never a source of truth: MySQL holds every
 * figure this system is judged on. So a cache that is unreachable, or that
 * throws for any other reason, must make the system slower and never wrong,
 * and never turn a working page into a 500. Every call here falls back to
 * computing the value from the database.
 *
 * Discovering that the cache is down is not free. Measured on this stack with
 * every node stopped: resolving a stopped container's name takes about four
 * seconds, and the client tries each seed in turn — twenty-four seconds before
 * it can report failure. A page that answers in twenty-four seconds is worse
 * for the user than one that fails, so a failure puts the cache out of use for
 * a cooldown, and the cooldown grows while it stays down: 10s, 30s, 1m, 2m,
 * then 5m. One request pays for the retry, the rest are served straight from
 * the database. The first success clears it.
 *
 * The cooldown is shared between processes through a marker file, because each
 * request is its own PHP process: an in-process flag would let every request
 * pay that discovery again. The file is local to the machine, so one server
 * giving up never speaks for another.
 *
 * Invalidation is by version, not by flushing: a cached key carries the
 * version of the namespace it belongs to, and a write bumps that version so
 * the old keys are never read again and expire on their own. Pattern deletes
 * and tag flushes need multi-key operations that a cluster spreads across
 * slots; a counter does not.
 *
 * Staleness is bounded twice over: by the version bump, and by the ttl each
 * caller passes, so a lost version counter cannot serve yesterday's numbers.
 */
class ResilientCache
{
    /** Cooldown after the 1st, 2nd, ... consecutive failure, in seconds. */
    private const COOLDOWNS = [10, 30, 60, 120, 300];

    private static ?float $degradedUntil = null;

    private static bool $loggedThisCooldown = false;

    /**
     * The cached value for a key, or the freshly computed one when the cache
     * cannot answer.
     *
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(string $namespace, string $key, int $ttl, Closure $compute): mixed
    {
        if ($this->isDegraded()) {
            return $compute();
        }

        $versioned = $this->key($namespace, $key);

        // Reading the version is itself a cache read, and it may have been the
        // one that found the cache down. Paying that discovery twice in one
        // call is what the cooldown exists to prevent.
        if ($this->isDegraded()) {
            return $compute();
        }

        try {
            $cached = Cache::get($versioned);
        } catch (\Throwable $e) {
            $this->degrade($e);

            return $compute();
        }

        // An answer is not proof that Redis gave it: under the failover store
        // the database answers when Redis cannot, and the only trace of that
        // is the breaker having just opened (redisFailedOver). Closing it on
        // such an answer would put Redis straight back in the chain, and
        // every other request would pay to find it down again.
        if ($this->isDegraded()) {
            return $compute();
        }

        $this->recovered();

        if ($cached !== null) {
            return $cached;
        }

        // Deliberately outside the try: a failure in here is the database's,
        // and blaming the cache for it would take the cache out of use.
        $value = $compute();

        if (! $this->isStorable($value, $namespace, $key)) {
            return $value;
        }

        try {
            Cache::put($versioned, $value, $ttl);
        } catch (\Throwable $e) {
            $this->degrade($e);
        }

        return $value;
    }

    /** Invalidate a namespace: later reads build keys the old entries cannot match. */
    public function bump(string $namespace): void
    {
        if ($this->isDegraded()) {
            return;
        }

        try {
            $bumped = Cache::increment($this->versionKey($namespace));

            /*
             * Stores disagree about incrementing a key that does not exist
             * yet: the array and redis stores create it at 1, the database
             * store refuses and answers false. The failover store passes on
             * whichever answered, so in the containers it is 1 while Redis
             * is up and false once it has fallen back to the database.
             * Either way the version is still the 1 that reads assume, and
             * the bump would retire nothing — so set it past that explicitly.
             */
            if ($bumped === false || $bumped === 1) {
                Cache::forever($this->versionKey($namespace), 2);
            }
        } catch (\Throwable $e) {
            $this->degrade($e);
        }
    }

    /**
     * The namespace's version. A missing counter reads as 1 rather than
     * failing; the ttl on each entry bounds what that can serve.
     */
    public function version(string $namespace): int
    {
        if ($this->isDegraded()) {
            return 1;
        }

        try {
            return (int) (Cache::get($this->versionKey($namespace)) ?: 1);
        } catch (\Throwable $e) {
            $this->degrade($e);

            return 1;
        }
    }

    /**
     * The failover store's chain for this request: Redis is left out while
     * the breaker is open, so the database answers at once instead of after
     * a connection attempt to every seed.
     *
     * @param  list<string>  $chain
     * @return list<string>
     */
    public function failoverChain(array $chain): array
    {
        return $this->isDegraded() ? array_values(array_diff($chain, ['redis'])) : $chain;
    }

    /**
     * Redis failed underneath the failover store (AppServiceProvider listens
     * for CacheFailedOver).
     *
     * The failover store answers from the database and keeps no memory of the
     * failure: its next call tries Redis again, and so does every request
     * after it. Measured with the whole cluster stopped, that was 5.6–9 s on
     * every page, indefinitely — correct answers, and nothing ever getting
     * better. Nor could the cooldown above help, because the failover store
     * catches the exception before anything here sees it. So open the breaker
     * from here, and rebuild the store without Redis for the rest of this
     * request; requests after this one start without it (failoverChain).
     */
    public function redisFailedOver(\Throwable $e): void
    {
        // Once per request: the store that tripped may report again before
        // it is replaced, and each report would lengthen the cooldown.
        if (self::$degradedUntil === null || microtime(true) >= self::$degradedUntil) {
            $this->degrade($e);
        }

        config(['cache.stores.failover.stores' => $this->failoverChain((array) config('cache.stores.failover.stores', []))]);
        Cache::forgetDriver('failover');
    }

    /** Whether the cache is being skipped right now. */
    public function isDegraded(): bool
    {
        if (self::$degradedUntil !== null) {
            if (microtime(true) < self::$degradedUntil) {
                return true;
            }

            self::$degradedUntil = null;
            self::$loggedThisCooldown = false;
        }

        // Another request may have found it down moments ago.
        return ($this->marker()['until'] ?? 0) > time();
    }

    /** A key for the current version of its namespace, so a bump retires it. */
    public function key(string $namespace, string $key): string
    {
        return "hris:{$namespace}:v".$this->version($namespace).':'.$key;
    }

    /** Forget a cooldown — for tests, and for an operator forcing a retry. */
    public static function reset(): void
    {
        self::$degradedUntil = null;
        self::$loggedThisCooldown = false;

        @unlink((new self)->markerPath());
    }

    /**
     * Whether a value can be cached without coming back different.
     *
     * The cache refuses to build objects out of what it stored
     * (config/cache.php serializable_classes is false, so a leaked APP_KEY
     * cannot be turned into a gadget chain), and hands back an incomplete
     * class instead. That is silent: the value looks stored, the page that
     * reads it back gets {} where a list should be, and only the second
     * request — the cached one — is wrong. So cache plain data only, and say
     * so loudly rather than caching something that will return corrupted.
     */
    private function isStorable(mixed $value, string $namespace, string $key): bool
    {
        if (! $this->holdsAnObject($value)) {
            return true;
        }

        $message = "Refusing to cache {$namespace}:{$key}: it holds an object, "
            .'and the cache only returns plain data. Convert it first (->toArray(), ->all()).';

        // A programming error, not a runtime condition: fail the suite that
        // introduces it. Serving the page uncached is the right answer
        // anywhere else.
        if (app()->runningUnitTests()) {
            throw new \LogicException($message);
        }

        Log::warning($message);

        return false;
    }

    private function holdsAnObject(mixed $value): bool
    {
        if (is_object($value)) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->holdsAnObject($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function degrade(\Throwable $e): void
    {
        $failures = (int) ($this->marker()['failures'] ?? 0) + 1;
        $cooldown = self::COOLDOWNS[min($failures, count(self::COOLDOWNS)) - 1];

        self::$degradedUntil = microtime(true) + $cooldown;

        // Best effort: if the marker cannot be written, the cooldown still
        // holds for this process.
        @file_put_contents(
            $this->markerPath(),
            (string) json_encode(['failures' => $failures, 'until' => time() + $cooldown]),
        );

        // Once per cooldown: a failing cache would otherwise write a line per
        // cached read, which is when the log is most needed and least readable.
        if (! self::$loggedThisCooldown) {
            self::$loggedThisCooldown = true;
            Log::warning('Cache unavailable; serving from the database.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'consecutive_failures' => $failures,
                'cooldown_seconds' => $cooldown,
            ]);
        }
    }

    /** The cache answered: drop any cooldown so the next failure starts over. */
    private function recovered(): void
    {
        if (is_file($this->markerPath())) {
            @unlink($this->markerPath());
            Log::info('Cache answering again.');
        }
    }

    /** @return array{failures?: int, until?: int} */
    private function marker(): array
    {
        $raw = @file_get_contents($this->markerPath());

        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function markerPath(): string
    {
        return storage_path('framework/cache/cache-degraded');
    }

    private function versionKey(string $namespace): string
    {
        return "hris:{$namespace}:version";
    }
}
