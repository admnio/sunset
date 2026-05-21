<?php

namespace Admnio\Sunset\Tests\Unit\QueuePause;

use Admnio\Sunset\Repositories\Redis\RedisQueuePauseRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Mockery;
use RuntimeException;

/**
 * Failure semantics for the Redis-backed pause repository.
 *
 * Reads must fail soft (return false / []) because the read path is hit on
 * every worker pop() — a Redis outage that fails-closed would silently stop
 * the entire fleet, which is worse than the small window where a recently-
 * paused queue keeps popping.
 *
 * Writes must re-throw — the caller (dashboard controller or artisan command)
 * surfaces the failure to the operator so they can retry. Silent write failure
 * here would be confusing: the operator clicks "Pause" and the dashboard says
 * "OK" while the queue keeps draining.
 */
class RedisQueuePauseRepositoryFailureTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_is_paused_returns_false_when_factory_throws(): void
    {
        $repo = new RedisQueuePauseRepository($this->throwingFactory(), $this->nullDispatcher());

        $this->assertFalse($repo->isPaused('redis', 'default'));
    }

    public function test_all_returns_empty_array_when_factory_throws(): void
    {
        $repo = new RedisQueuePauseRepository($this->throwingFactory(), $this->nullDispatcher());

        $this->assertSame([], $repo->all());
    }

    public function test_pause_rethrows_when_factory_throws(): void
    {
        $repo = new RedisQueuePauseRepository($this->throwingFactory(), $this->nullDispatcher());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis unavailable');

        $repo->pause('redis', 'default', 'dashboard');
    }

    public function test_resume_rethrows_when_factory_throws(): void
    {
        $repo = new RedisQueuePauseRepository($this->throwingFactory(), $this->nullDispatcher());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis unavailable');

        $repo->resume('redis', 'default', 'cli');
    }

    private function throwingFactory(): RedisFactory
    {
        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')
            ->andThrow(new RuntimeException('Redis unavailable'));

        return $factory;
    }

    private function nullDispatcher(): Dispatcher
    {
        // Loose mock — any dispatch call is fine, and in the failure paths the
        // dispatcher is never reached anyway because the Redis call throws first.
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->zeroOrMoreTimes();

        return $events;
    }
}
