<?php

namespace Admnio\Sunset\Tests\Integration\RateLimiting;

use Admnio\Sunset\Events\JobRateLimited;
use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\Tests\Fixtures\Jobs\RecordingJob;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * v0.7.0 — a single Limit can carry BOTH a throttle and a concurrency spec.
 * RedisLimiter::check() evaluates both atomically (well, sequentially across
 * two Lua scripts) and the gate composes the decisions via Decision::merge —
 * any reject wins, rolling back prior admits in the same call.
 *
 * NOTE: LimitBuilder names limits by target alone ("queue:<name>"). Two
 * separate Sunset::for() calls overwrite each other in the registry. To
 * compose throttle AND concurrency, chain them on a SINGLE for() call.
 */
class CompositionTest extends IntegrationTestCase
{
    private const TEST_QUEUE = 'rl-compose';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->forgetInstance(LimitRegistry::class);
        $this->purgeRedisRlState();
        $this->purgeQueue(self::TEST_QUEUE);

        config([
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => self::TEST_QUEUE,
        ]);

        @unlink(sys_get_temp_dir() . '/sunset-marker');
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeRedisRlState();
            $this->purgeQueue(self::TEST_QUEUE);
        } catch (\Throwable $e) {
            // best-effort
        }

        @unlink(sys_get_temp_dir() . '/sunset-marker');

        parent::tearDown();
    }

    public function test_multiple_limits_on_same_queue_compose_with_max_retryAfter(): void
    {
        // Chained: a SINGLE Limit with both throttle and concurrency specs.
        // RedisLimiter::check() evaluates throttle first, then concurrency
        // (rolling back the throttle entry if concurrency rejects).
        Sunset::for(self::TEST_QUEUE)
            ->throttle(perMinute: 5)
            ->concurrency(1);

        Queue::push(new RecordingJob('compose-1'));
        Queue::push(new RecordingJob('compose-2'));
        Queue::push(new RecordingJob('compose-3'));

        // First pop: throttle admits (1/5), concurrency admits (1/1). Hold
        // the slot — do NOT delete or fire — to exercise the concurrency cap.
        $first = Queue::connection('redis')->pop(self::TEST_QUEUE);
        $this->assertNotNull(
            $first,
            'First pop must admit both throttle (1/5) and concurrency (1/1).'
        );

        Event::fake([JobRateLimited::class]);

        // Second pop: concurrency rejects because the slot is held. The
        // throttle entry created in this same check is rolled back inside
        // RedisLimiter::check(), so the second job's reject does NOT burn a
        // throttle slot — only the first job's throttle entry remains.
        $second = Queue::connection('redis')->pop(self::TEST_QUEUE);
        $this->assertNull(
            $second,
            'Second pop must be rejected by concurrency (slot full).'
        );

        // Third pop: same — concurrency rejects.
        $third = Queue::connection('redis')->pop(self::TEST_QUEUE);
        $this->assertNull(
            $third,
            'Third pop must also be rejected by concurrency.'
        );

        Event::assertDispatched(JobRateLimited::class, function (JobRateLimited $e) {
            // The limit name is derived from the target — "queue:<name>". The
            // retry-after approximates the held slot's remaining TTL; the
            // default slot TTL is max(retry_after) + 60s across all queue
            // connections, so the value sits inside that bound. We assert a
            // generous upper bound rather than computing exactly because the
            // testbench default queue config can shift across Laravel minors.
            return $e->limitName === 'queue:' . self::TEST_QUEUE
                && $e->retryAfterSeconds >= 1
                && $e->retryAfterSeconds <= 600
                && $e->queueName === self::TEST_QUEUE
                && $e->strategy === 'release-computed';
        });
    }

    private function purgeRedisRlState(): void
    {
        /** @var RedisFactory $factory */
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');

        $prefix = $this->detectPrefix($conn);

        foreach ((array) $conn->keys('sunset:rl:*') as $key) {
            $bare = ($prefix !== '' && str_starts_with($key, $prefix))
                ? substr($key, strlen($prefix))
                : $key;
            $conn->del($bare);
        }
    }

    private function purgeQueue(string $name): void
    {
        /** @var RedisFactory $factory */
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');
        $conn->del("queues:{$name}");
        $conn->del("queues:{$name}:delayed");
        $conn->del("queues:{$name}:reserved");
        $conn->del("queues:{$name}:notify");
    }

    private function detectPrefix($conn): string
    {
        try {
            return (string) $conn->client()->getOption(\Redis::OPT_PREFIX);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
