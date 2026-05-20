<?php

namespace Admnio\Sunset\Tests\Unit\Transports\Redis;

use Admnio\Sunset\Transports\Redis\RedisQueue;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobReserved;
use Admnio\Sunset\RateLimiting\Decision;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\RateLimiting\RateLimitGate;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Support\Facades\Event;
use Mockery;

class RedisQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset the singleton registry so each test starts with no limits.
        $this->app->forgetInstance(LimitRegistry::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_push_raw_dispatches_sunset_events_around_send(): void
    {
        Event::fake([JobQueueing::class, JobQueued::class]);

        $factory = $this->app->make(RedisFactory::class);
        $queue = new RedisQueue($factory, 'default', 'default');
        $queue->setContainer($this->app);
        $queue->setConnectionName('redis');

        $queueName = 'rq-test-' . uniqid();
        try {
            $queue->pushRaw(json_encode(['id' => 'abc', 'displayName' => 'TestJob', 'data' => []]), $queueName);

            Event::assertDispatched(JobQueueing::class, function ($e) {
                return $e->connectionName === 'redis';
            });
            Event::assertDispatched(JobQueued::class, function ($e) {
                return $e->connectionName === 'redis';
            });
        } finally {
            $factory->connection('default')->del("queues:{$queueName}");
        }
    }

    public function test_pop_dispatches_job_reserved(): void
    {
        Event::fake([JobReserved::class]);

        $factory = $this->app->make(RedisFactory::class);
        $queue = new RedisQueue($factory, 'default', 'default');
        $queue->setContainer($this->app);
        $queue->setConnectionName('redis');

        $queueName = 'rq-test-pop-' . uniqid();
        try {
            $factory->connection('default')->rpush(
                "queues:{$queueName}",
                json_encode(['id' => 'abc', 'displayName' => 'TestJob', 'data' => [], 'attempts' => 0])
            );

            $job = $queue->pop($queueName);
            $this->assertNotNull($job);

            Event::assertDispatched(JobReserved::class);
        } finally {
            $factory->connection('default')->del("queues:{$queueName}");
            $factory->connection('default')->del("queues:{$queueName}:reserved");
        }
    }

    /**
     * With NO Sunset::for() limits registered, the gate's empty-registry
     * short-circuit returns Decision::admit() without touching Redis, so
     * pop() returns the job unchanged. Exercises the real gate (no mock)
     * to confirm the zero-overhead path through actual production code.
     */
    public function test_pop_returns_job_unchanged_when_no_limits_registered(): void
    {
        $factory = $this->app->make(RedisFactory::class);
        $queue = new RedisQueue($factory, 'default', 'default');
        $queue->setContainer($this->app);
        $queue->setConnectionName('redis');

        $queueName = 'rq-test-nolimits-' . uniqid();
        try {
            $factory->connection('default')->rpush(
                "queues:{$queueName}",
                json_encode(['id' => 'abc', 'displayName' => 'TestJob', 'data' => [], 'attempts' => 0])
            );

            $job = $queue->pop($queueName);
            $this->assertNotNull($job);
        } finally {
            $factory->connection('default')->del("queues:{$queueName}");
            $factory->connection('default')->del("queues:{$queueName}:reserved");
        }
    }

    /**
     * With a registered limit that REJECTS, pop() must return null because
     * the gate has already taken ownership of the job (release/fail/delete).
     * Uses a mock gate to isolate the transport's pop() wiring — proving the
     * gate is invoked with the right shape AND that pop() honors the
     * Decision::reject() result. The real-Redis gate-rejection roundtrip
     * lives in B8 integration tests.
     */
    public function test_pop_returns_null_when_gate_rejects(): void
    {
        $factory = $this->app->make(RedisFactory::class);
        $queue = new RedisQueue($factory, 'default', 'default');
        $queue->setContainer($this->app);
        $queue->setConnectionName('redis');

        $capturedPayload = null;
        $capturedQueue = null;
        $gate = Mockery::mock(RateLimitGate::class);
        $gate->shouldReceive('admit')
            ->once()
            ->withArgs(function (JobContract $job, array $payload, string $queueArg, array $tags)
                use (&$capturedPayload, &$capturedQueue) {
                $capturedPayload = $payload;
                $capturedQueue = $queueArg;
                return true;
            })
            ->andReturnUsing(function (JobContract $job) {
                // Mimic the gate's contract: it must own the job before returning
                // a reject. The transport must NOT touch the job after this.
                $job->release(30);
                return Decision::reject(30);
            });

        $this->app->instance(RateLimitGate::class, $gate);

        $queueName = 'rq-test-reject-' . uniqid();
        try {
            $factory->connection('default')->rpush(
                "queues:{$queueName}",
                json_encode([
                    'id' => 'abc',
                    'displayName' => 'TestJob',
                    'tags' => ['tag-a'],
                    'data' => [],
                    'attempts' => 0,
                ])
            );

            $result = $queue->pop($queueName);
            $this->assertNull($result);

            // Verify the payload shape passed to the gate.
            $this->assertIsArray($capturedPayload);
            $this->assertSame('redis', $capturedPayload['connection']);
            $this->assertSame(['tag-a'], $capturedPayload['tags']);
            $this->assertSame($queueName, $capturedQueue);
        } finally {
            $factory->connection('default')->del("queues:{$queueName}");
            $factory->connection('default')->del("queues:{$queueName}:reserved");
            $factory->connection('default')->del("queues:{$queueName}:delayed");
        }
    }
}
