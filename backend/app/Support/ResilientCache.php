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

        try {
            $value = Cache::remember($this->key($namespace, $key), $ttl, $compute);
            $this->recovered();

            return $value;
        } catch (\Throwable $e) {
            $this->degrade($e);

            return $compute();
        }
    }

    /** Invalidate a namespace: later reads build keys the old entries cannot match. */
    public function bump(string $namespace): void
    {
        if ($this->isDegraded()) {
            return;
        }

        try {
            // A missing counter increments to 1, which is the version reads
            // already assume, so the bump would retire nothing. Move it past.
            if (Cache::increment($this->versionKey($namespace)) === 1) {
                Cache::increment($this->versionKey($namespace));
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
