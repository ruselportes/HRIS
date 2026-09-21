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

    /**
     * Stores disagree about incrementing a key that does not exist: the array
     * and redis stores create it at 1, the database store — the containers'
     * fallback — refuses and answers false. A bump has to retire keys either
     * way; this went unnoticed once because the tests ran on the forgiving
     * store.
     */
    public function test_a_store_that_will_not_create_a_counter_still_retires_its_keys(): void
    {
        Cache::shouldReceive('get')->andReturn(null, 2);
        Cache::shouldReceive('increment')->once()->andReturn(false);
        Cache::shouldReceive('forever')->once()->with('hris:employees:version', 2);

        $before = $this->cache->key('employees', 'index');
        $this->cache->bump('employees');

        $this->assertNotSame($before, $this->cache->key('employees', 'index'));
    }

    /**
     * Caching an object is a mistake that hides: the store keeps it, and the
     * request that reads it back gets an incomplete class. It must be caught
     * where it is written, not in a browser two requests later.
     */
    public function test_caching_an_object_is_refused_rather_than_stored(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('reference:sites');

        $this->cache->remember('reference', 'sites', 60, fn () => collect([['site_id' => 1]]));
    }

    public function test_an_object_nested_in_a_cached_array_is_refused_too(): void
    {
        $this->expectException(\LogicException::class);

        $this->cache->remember('reports', 'overview', 60, fn () => ['rows' => [(object) ['a' => 1]]]);
    }

    /** A database failure is the database's: it may not retire the cache. */
    public function test_a_failing_computation_does_not_take_the_cache_out_of_use(): void
    {
        try {
            $this->cache->remember('reports', 'k', 60, fn () => throw new RuntimeException('SQLSTATE[HY000]'));
        } catch (RuntimeException) {
            // Expected: the caller sees the database's error.
        }

        $this->assertFalse($this->cache->isDegraded());
    }

    /**
     * An edit made while the cache was unreachable could not retire what the
     * cache held. When the cache answers again, that entry must not be what
     * the first reader gets.
     */
    public function test_a_write_during_an_outage_is_honoured_once_the_cache_answers_again(): void
    {
        $this->cache->remember('employees', 'index', 60, fn () => 'before the edit');

        // Another request found the cache down; this write lands meanwhile.
        $this->writeMarker(['failures' => 1, 'until' => time() + 10]);
        $this->cache->bump('employees');

        // The cooldown ends and the cache answers — with the old entry.
        $this->writeMarker([...$this->readMarker(), 'until' => time() - 1]);

        $this->assertSame(
            'after the edit',
            $this->cache->remember('employees', 'index', 60, fn () => 'after the edit'),
        );
        $this->assertFileDoesNotExist($this->markerPath());
    }

    /** What is owed survives the cache failing again before it is paid. */
    public function test_a_pending_retirement_outlasts_a_second_failure(): void
    {
        Log::spy();
        $this->writeMarker(['failures' => 1, 'until' => time() + 10, 'pending' => ['reports']]);

        Cache::shouldReceive('get')->andThrow(new RuntimeException('still down'));
        $this->writeMarker([...$this->readMarker(), 'until' => time() - 1]);
        $this->cache->remember('employees', 'index', 60, fn () => 'x');

        $this->assertSame(['reports'], $this->readMarker()['pending'] ?? null);
        $this->assertSame(2, $this->readMarker()['failures'] ?? null);
    }

    public function test_a_bumped_namespace_recomputes_instead_of_serving_the_old_entry(): void
    {
        $this->cache->remember('employees', 'index', 60, fn () => 'before');

        $this->cache->bump('employees');

        $this->assertSame('after', $this->cache->remember('employees', 'index', 60, fn () => 'after'));
    }

    /** @param  array<string, mixed>  $marker */
    private function writeMarker(array $marker): void
    {
        file_put_contents($this->markerPath(), (string) json_encode($marker));
    }

    /** @return array<string, mixed> */
    private function readMarker(): array
    {
        return json_decode((string) @file_get_contents($this->markerPath()), true) ?? [];
    }

    private function markerPath(): string
    {
        return storage_path('framework/cache/cache-degraded');
    }
}
