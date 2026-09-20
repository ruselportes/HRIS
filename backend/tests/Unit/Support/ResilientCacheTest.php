<?php

namespace Tests\Unit\Support;

use App\Support\ResilientCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * The cache may make the system slower. It may never make it wrong, and it may
 * never make a working page fail.
 */
class ResilientCacheTest extends TestCase
{
    private ResilientCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        ResilientCache::reset();
        $this->cache = new ResilientCache;
    }

    protected function tearDown(): void
    {
        ResilientCache::reset();

        parent::tearDown();
    }

    public function test_a_working_cache_answers_the_second_read(): void
    {
        $computed = 0;
        $compute = function () use (&$computed) {
            $computed++;

            return 'value';
        };

        $this->assertSame('value', $this->cache->remember('reports', 'k', 60, $compute));
        $this->assertSame('value', $this->cache->remember('reports', 'k', 60, $compute));
        $this->assertSame(1, $computed, 'The second read should come from the cache.');
    }

    public function test_a_failing_cache_serves_from_the_database_rather_than_failing(): void
    {
        Log::spy();
        // The version lookup is the first thing to touch the store.
        Cache::shouldReceive('get')->once()->andThrow(new RuntimeException('Connection refused: redis-1:6379'));

        $value = $this->cache->remember('reports', 'k', 60, fn () => 'from the database');

        $this->assertSame('from the database', $value);
        $this->assertTrue($this->cache->isDegraded());
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * Without the cooldown, every cached read in the request would wait on its
     * own connection attempt, and a dead cache would be slower than none.
     */
    public function test_after_a_failure_the_cache_is_skipped_for_a_cooldown(): void
    {
        Log::spy();
        // Exactly once for the whole test: the reads after the failure must
        // not reach the store at all.
        Cache::shouldReceive('get')->once()->andThrow(new RuntimeException('down'));

        $this->cache->remember('reports', 'k', 60, fn () => 'first');

        $this->assertSame('second', $this->cache->remember('reports', 'k', 60, fn () => 'second'));
        $this->assertSame(1, $this->cache->version('reports'), 'A skipped cache reads as version 1.');
        $this->cache->bump('reports');

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_bump_retires_the_keys_of_that_namespace_only(): void
    {
        $employees = $this->cache->key('employees', 'index');
        $reports = $this->cache->key('reports', 'overview');

        $this->cache->bump('employees');

        $this->assertNotSame($employees, $this->cache->key('employees', 'index'));
        $this->assertSame($reports, $this->cache->key('reports', 'overview'));
    }

    public function test_a_bumped_namespace_recomputes_instead_of_serving_the_old_entry(): void
    {
        $this->cache->remember('employees', 'index', 60, fn () => 'before');

        $this->cache->bump('employees');

        $this->assertSame('after', $this->cache->remember('employees', 'index', 60, fn () => 'after'));
    }
}
