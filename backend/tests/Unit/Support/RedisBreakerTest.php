<?php

namespace Tests\Unit\Support;

use App\Support\ResilientCache;
use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * The circuit breaker on the failover cache store (Redis Cluster add-on).
 *
 * Laravel's failover store answers from the database when Redis fails, but
 * keeps no memory of it: every call tries Redis again. With the whole cluster
 * stopped that was 5.6–9 s on every page, forever. These pin the fix — once
 * Redis is found down, nothing tries it again until the cooldown ends — with
 * a stand-in registered as the `redis` store, so no test needs a cluster.
 */
class RedisBreakerTest extends TestCase
{
    private ResilientCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        ResilientCache::reset();
        Log::spy();
        $this->cache = app(ResilientCache::class);
    }

    protected function tearDown(): void
    {
        ResilientCache::reset();

        parent::tearDown();
    }

    public function test_redis_failing_under_the_failover_store_opens_the_breaker(): void
    {
        $this->useRedis($this->redisThatFails(fn () => true));

        Cache::put('k', 'v', 60);

        $this->assertSame('v', Cache::get('k'), 'The fallback store should have answered.');
        $this->assertTrue($this->cache->isDegraded());
        $this->assertFileExists($this->marker(), 'Other requests learn of it through the marker.');
    }

    public function test_once_open_the_rest_of_the_request_does_not_try_redis(): void
    {
        $redis = $this->redisThatFails(fn () => true);
        $this->useRedis($redis);

        Cache::put('a', 1, 60);
        Cache::get('a');
        Cache::get('b');
        Cache::put('c', 3, 60);
        Cache::increment('d');

        $this->assertSame(1, $redis->calls, 'Only the first call should have paid for finding Redis down.');
    }

    /** Another request found it down moments ago: this one starts without it. */
    public function test_a_later_request_starts_without_redis_while_the_cooldown_lasts(): void
    {
        $this->writeMarker(until: time() + 10);

        $this->refreshApplication();

        $this->assertSame(['database'], config('cache.stores.failover.stores'));
    }

    public function test_after_the_cooldown_redis_is_tried_again(): void
    {
        $this->writeMarker(until: time() - 1);

        $this->refreshApplication();

        $this->assertSame(['redis', 'database'], config('cache.stores.failover.stores'));
    }

    /**
     * Redis answers the version read, then dies before the value read, and
     * the database answers that. The read succeeded — but the breaker must
     * stay open, or the next request pays to find Redis down all over again.
     */
    public function test_a_fallback_answer_is_not_taken_for_redis_recovering(): void
    {
        $this->useRedis($this->redisThatFails(fn (string $key) => ! str_ends_with($key, ':version')));

        $value = $this->cache->remember('reports', 'overview', 60, fn () => 'computed');

        $this->assertSame('computed', $value);
        $this->assertFileExists($this->marker(), 'The breaker closed on an answer the database gave.');
    }

    public function test_the_breaker_closes_once_redis_answers_again(): void
    {
        $this->writeMarker(until: time() - 1, failures: 2);
        $this->useRedis($this->redisThatFails(fn () => false));

        $this->cache->remember('reports', 'k', 60, fn () => 'x');

        $this->assertFileDoesNotExist($this->marker(), 'A real answer from Redis should reset the backoff.');
    }

    /**
     * An array store that throws for the keys $failsFor picks, the way an
     * unreachable cluster throws, and counts how often it is asked.
     *
     * @param  Closure(string): bool  $failsFor
     */
    private function redisThatFails(Closure $failsFor): ArrayStore
    {
        return new class($failsFor) extends ArrayStore
        {
            public int $calls = 0;

            public function __construct(private Closure $failsFor)
            {
                parent::__construct();
            }

            public function get($key)
            {
                $this->attempt($key);

                return parent::get($key);
            }

            public function put($key, $value, $seconds)
            {
                $this->attempt($key);

                return parent::put($key, $value, $seconds);
            }

            public function increment($key, $value = 1)
            {
                $this->attempt($key);

                return parent::increment($key, $value);
            }

            public function decrement($key, $value = 1)
            {
                $this->attempt($key);

                return parent::decrement($key, $value);
            }

            public function forever($key, $value)
            {
                $this->attempt($key);

                return parent::forever($key, $value);
            }

            public function forget($key)
            {
                $this->attempt($key);

                return parent::forget($key);
            }

            private function attempt(string $key): void
            {
                $this->calls++;

                if (($this->failsFor)($key)) {
                    throw new RuntimeException('Timed out attempting to find data in the correct node');
                }
            }
        };
    }

    /** Make $redis the `redis` store, behind the failover store. */
    private function useRedis(ArrayStore $redis): void
    {
        Cache::extend('stand-in', fn () => Cache::repository($redis));

        config([
            'cache.default' => 'failover',
            'cache.stores.redis' => ['driver' => 'stand-in'],
            'cache.stores.failover.stores' => ['redis', 'array'],
        ]);

        Cache::forgetDriver(['redis', 'failover']);
    }

    private function writeMarker(int $until, int $failures = 1): void
    {
        file_put_contents($this->marker(), (string) json_encode(['failures' => $failures, 'until' => $until]));
    }

    private function marker(): string
    {
        return storage_path('framework/cache/cache-degraded');
    }
}
