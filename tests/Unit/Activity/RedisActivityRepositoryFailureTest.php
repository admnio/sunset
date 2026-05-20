<?php

namespace Admnio\Sunset\Tests\Unit\Activity;

use Admnio\Sunset\Activity\ActivityEvent;
use Admnio\Sunset\Repositories\Redis\RedisActivityRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Mockery;
use RuntimeException;

/**
 * Read-path failure semantics: when Redis is unavailable, the repository
 * degrades gracefully so the dashboard renders an empty activity log rather
 * than 500ing.
 *
 * record() intentionally does NOT swallow — the recorder layer is responsible
 * for silencing activity-write failures (telemetry is observability, not
 * load-bearing).
 */
class RedisActivityRepositoryFailureTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_recent_returns_empty_array_when_factory_throws(): void
    {
        $repo = new RedisActivityRepository($this->throwingFactory());

        $this->assertSame([], $repo->recent(50));
    }

    public function test_since_returns_empty_array_when_factory_throws(): void
    {
        $repo = new RedisActivityRepository($this->throwingFactory());

        $this->assertSame([], $repo->since(10, 100));
    }

    public function test_before_returns_empty_array_when_factory_throws(): void
    {
        $repo = new RedisActivityRepository($this->throwingFactory());

        $this->assertSame([], $repo->before(10, 100));
    }

    public function test_record_rethrows_when_factory_throws(): void
    {
        $repo = new RedisActivityRepository($this->throwingFactory());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis unavailable');

        $repo->record(new ActivityEvent(
            id: 0,
            type: 'job_failed',
            occurredAt: 1_700_000_000,
            payload: ['foo' => 'bar'],
        ));
    }

    private function throwingFactory(): RedisFactory
    {
        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')
            ->andThrow(new RuntimeException('Redis unavailable'));

        return $factory;
    }
}
