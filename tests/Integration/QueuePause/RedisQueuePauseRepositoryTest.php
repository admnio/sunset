<?php

namespace Admnio\Sunset\Tests\Integration\QueuePause;

use Admnio\Sunset\Events\QueuePaused;
use Admnio\Sunset\Events\QueueResumed;
use Admnio\Sunset\Repositories\Redis\RedisQueuePauseRepository;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Event;

/**
 * Exercises the Redis-backed pause repository against a real Redis instance.
 * Mirrors the integration-test pattern used by RedisActivityRepositoryTest —
 * the suite expects `redis-server` reachable at REDIS_HOST:REDIS_PORT (defaults
 * to 127.0.0.1:6379, database 1 per the TestCase config).
 */
class RedisQueuePauseRepositoryTest extends IntegrationTestCase
{
    private RedisQueuePauseRepository $repo;

    /** @var \Illuminate\Redis\Connections\Connection */
    private $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = $this->app->make(RedisFactory::class);
        $this->redis = $factory->connection('default');

        // FLUSHDB-equivalent: wipe any leftover sunset:* keys from prior runs.
        foreach ($this->redis->keys('sunset:*') as $key) {
            $name = str_replace($this->redis->_prefix(''), '', $key);
            $this->redis->del($name);
        }

        $this->repo = $this->makeRepo();
    }

    /**
     * Build a fresh repo bound to the CURRENT facade dispatcher. Call this AFTER
     * Event::fake([...]) in tests that assert dispatched events, so the repo
     * captures the fake dispatcher rather than the real one that was active when
     * setUp() ran.
     */
    private function makeRepo(): RedisQueuePauseRepository
    {
        return new RedisQueuePauseRepository(
            $this->app->make(RedisFactory::class),
            $this->app->make(Dispatcher::class),
        );
    }

    public function test_pause_adds_member_to_set_and_dispatches_event(): void
    {
        Event::fake([QueuePaused::class]);
        $this->repo = $this->makeRepo();

        $this->repo->pause('redis', 'default', 'cli');

        $this->assertSame(
            1,
            (int) $this->redis->sismember('sunset:queues:paused', 'redis:default'),
            'pause() should SADD the {connection}:{queue} member',
        );

        Event::assertDispatched(
            QueuePaused::class,
            fn (QueuePaused $e) => $e->connection === 'redis'
                && $e->queue === 'default'
                && $e->actor === 'cli',
        );
    }

    public function test_pause_dispatches_with_null_actor_when_omitted(): void
    {
        Event::fake([QueuePaused::class]);
        $this->repo = $this->makeRepo();

        $this->repo->pause('redis', 'default');

        Event::assertDispatched(
            QueuePaused::class,
            fn (QueuePaused $e) => $e->connection === 'redis'
                && $e->queue === 'default'
                && $e->actor === null,
        );
    }

    public function test_resume_removes_member_from_set_and_dispatches_event(): void
    {
        $this->repo->pause('redis', 'default', 'cli');

        Event::fake([QueueResumed::class]);
        $this->repo = $this->makeRepo();

        $this->repo->resume('redis', 'default', 'cli');

        $this->assertSame(
            0,
            (int) $this->redis->sismember('sunset:queues:paused', 'redis:default'),
            'resume() should SREM the {connection}:{queue} member',
        );

        Event::assertDispatched(
            QueueResumed::class,
            fn (QueueResumed $e) => $e->connection === 'redis'
                && $e->queue === 'default'
                && $e->actor === 'cli',
        );
    }

    public function test_is_paused_returns_true_for_paused_and_false_for_unpaused(): void
    {
        $this->repo->pause('redis', 'default', 'cli');

        $this->assertTrue($this->repo->isPaused('redis', 'default'));
        $this->assertFalse($this->repo->isPaused('redis', 'emails'));
        $this->assertFalse($this->repo->isPaused('sqs', 'default'));
    }

    public function test_is_paused_returns_false_after_resume(): void
    {
        $this->repo->pause('redis', 'default', 'cli');
        $this->assertTrue($this->repo->isPaused('redis', 'default'));

        $this->repo->resume('redis', 'default', 'cli');
        $this->assertFalse($this->repo->isPaused('redis', 'default'));
    }

    public function test_all_returns_shape_with_cross_connection_isolation(): void
    {
        $this->repo->pause('redis', 'default', 'cli');
        $this->repo->pause('sqs', 'default', 'cli');

        $all = $this->repo->all();

        $this->assertCount(2, $all);

        // SMEMBERS doesn't promise an order, so compare as sets.
        $this->assertEqualsCanonicalizing(
            [
                ['connection' => 'redis', 'queue' => 'default'],
                ['connection' => 'sqs', 'queue' => 'default'],
            ],
            $all,
        );
    }

    public function test_all_returns_empty_array_when_no_queues_paused(): void
    {
        $this->assertSame([], $this->repo->all());
    }

    public function test_double_pause_is_storage_idempotent_but_event_fires_twice(): void
    {
        Event::fake([QueuePaused::class]);
        $this->repo = $this->makeRepo();

        $this->repo->pause('redis', 'default', 'cli');
        $this->repo->pause('redis', 'default', 'cli');

        // SADD is idempotent at the Redis layer — set cardinality stays 1.
        $this->assertSame(
            1,
            (int) $this->redis->scard('sunset:queues:paused'),
            'SADD on an existing member must not create duplicates',
        );

        // ...but the event represents the operator's action, not the state delta,
        // so it fires once per pause() call. The audit trail faithfully records
        // what the operator did.
        Event::assertDispatchedTimes(QueuePaused::class, 2);
    }

    public function test_double_resume_is_storage_idempotent_but_event_fires_twice(): void
    {
        Event::fake([QueueResumed::class]);
        $this->repo = $this->makeRepo();

        // No prior pause — first resume is a no-op SREM, second is also a no-op.
        $this->repo->resume('redis', 'default', 'cli');
        $this->repo->resume('redis', 'default', 'cli');

        $this->assertSame(
            0,
            (int) $this->redis->scard('sunset:queues:paused'),
        );

        Event::assertDispatchedTimes(QueueResumed::class, 2);
    }

    public function test_all_splits_member_on_first_colon_only(): void
    {
        // Queue names may contain colons (e.g. "foo:bar" for app-specific
        // namespacing). Connection names won't. The repo must split on the
        // FIRST colon so the queue field preserves embedded colons.
        $this->repo->pause('redis', 'foo:bar', 'cli');

        $all = $this->repo->all();

        $this->assertSame(
            [['connection' => 'redis', 'queue' => 'foo:bar']],
            $all,
        );
    }
}
