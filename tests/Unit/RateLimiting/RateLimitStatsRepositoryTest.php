<?php

namespace Admnio\Sunset\Tests\Unit\RateLimiting;

use Admnio\Sunset\RateLimiting\RateLimitStatsRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

class RateLimitStatsRepositoryTest extends TestCase
{
    private RateLimitStatsRepository $stats;
    private mixed $redis;

    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->redis = $this->app->make(RedisFactory::class)
                ->connection(config('sunset.redis_connection'));
            $this->redis->ping();
        } catch (Throwable) {
            $this->markTestSkipped('Redis unavailable');
        }

        // Clean any stale rejection counters left from prior tests.
        $this->flushRejectKeys();

        $this->stats = new RateLimitStatsRepository(
            $this->app->make(RedisFactory::class),
            config('sunset.redis_connection'),
        );
    }

    protected function tearDown(): void
    {
        try {
            $this->flushRejectKeys();
        } catch (Throwable) {
            // ignore
        }
        parent::tearDown();
    }

    private function flushRejectKeys(): void
    {
        $prefix = (string) config('database.redis.options.prefix', '');
        foreach ((array) $this->redis->keys('sunset:rl:rejects:*') as $k) {
            $logical = $prefix !== '' && str_starts_with($k, $prefix)
                ? substr($k, strlen($prefix))
                : $k;
            $this->redis->del($logical);
        }
    }

    public function test_returns_empty_when_no_counters(): void
    {
        $this->assertSame([], $this->stats->rejectsByLimit());
    }

    public function test_returns_rows_for_existing_counters(): void
    {
        $this->redis->set('sunset:rl:rejects:redis:geocode:queue:geocode', 7);
        $this->redis->set('sunset:rl:rejects:sqs:emails:queue:emails', 3);

        $rows = $this->stats->rejectsByLimit();
        $this->assertCount(2, $rows);

        $byLimit = collect($rows)->keyBy('limit')->all();
        $this->assertSame(7, $byLimit['queue:geocode']['count']);
        $this->assertSame('redis', $byLimit['queue:geocode']['connection']);
        $this->assertSame('geocode', $byLimit['queue:geocode']['queue']);
    }

    public function test_sorts_by_count_descending(): void
    {
        $this->redis->set('sunset:rl:rejects:redis:a:queue:a', 1);
        $this->redis->set('sunset:rl:rejects:redis:b:queue:b', 5);
        $this->redis->set('sunset:rl:rejects:redis:c:queue:c', 3);

        $rows = $this->stats->rejectsByLimit();
        $counts = array_map(fn ($r) => $r['count'], $rows);
        $this->assertSame([5, 3, 1], $counts);
    }

    public function test_skips_malformed_keys(): void
    {
        $this->redis->set('sunset:rl:rejects:nokeyparts', 99);
        $rows = $this->stats->rejectsByLimit();
        $this->assertCount(0, $rows);
    }
}
