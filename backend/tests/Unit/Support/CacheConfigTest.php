<?php

namespace Tests\Unit\Support;

use Tests\TestCase;

/**
 * The cache configuration the Redis Cluster add-on depends on.
 *
 * Pinned because it failed once with nothing else noticing: config/cache.php
 * carried a second `failover` entry from the Laravel skeleton, the later key
 * won, and the chain became database-then-array. Redis sat idle while every
 * page and every other test passed — the database store gives the same
 * answers, only slower — so only a test that reads the chain itself could
 * have caught it.
 */
class CacheConfigTest extends TestCase
{
    /**
     * Read from the file, not from config(): the breaker legitimately drops
     * Redis from the running chain while it is down, and the tests share
     * storage/ — so the cooldown marker — with the development stack.
     */
    public function test_the_failover_store_tries_redis_first_then_the_database(): void
    {
        $config = require config_path('cache.php');

        $this->assertSame(['redis', 'database'], $config['stores']['failover']['stores']);
    }
}
