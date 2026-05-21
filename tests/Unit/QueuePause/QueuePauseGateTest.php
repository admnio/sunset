<?php

namespace Admnio\Sunset\Tests\Unit\QueuePause;

use Admnio\Sunset\Contracts\QueuePauseRepository;
use Admnio\Sunset\QueuePause\QueuePauseGate;
use Admnio\Sunset\Tests\TestCase;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for QueuePauseGate's fail-open + throttled-warning semantics.
 *
 * The gate is hit on every worker pop(); a Redis blip therefore must not turn
 * into a fleet-wide stoppage (fail open) nor into a log-spam flood (throttle
 * the warning). Both behaviours are tested here with mocks + an injected clock
 * so the test never sleeps.
 */
class QueuePauseGateTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_delegates_to_repository_and_returns_true_when_paused(): void
    {
        $repo = Mockery::mock(QueuePauseRepository::class);
        $repo->shouldReceive('isPaused')
            ->once()
            ->with('redis', 'default')
            ->andReturnTrue();

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldNotReceive('warning');

        $gate = new QueuePauseGate($repo, $logger, $this->fixedClock(1000.0));

        $this->assertTrue($gate->isPaused('redis', 'default'));
    }

    public function test_delegates_to_repository_and_returns_false_when_not_paused(): void
    {
        $repo = Mockery::mock(QueuePauseRepository::class);
        $repo->shouldReceive('isPaused')
            ->once()
            ->with('sqs', 'emails')
            ->andReturnFalse();

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldNotReceive('warning');

        $gate = new QueuePauseGate($repo, $logger, $this->fixedClock(1000.0));

        $this->assertFalse($gate->isPaused('sqs', 'emails'));
    }

    public function test_returns_false_when_repository_throws(): void
    {
        $repo = Mockery::mock(QueuePauseRepository::class);
        $repo->shouldReceive('isPaused')
            ->once()
            ->andThrow(new RuntimeException('Redis unavailable'));

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();

        $gate = new QueuePauseGate($repo, $logger, $this->fixedClock(1000.0));

        $this->assertFalse($gate->isPaused('redis', 'default'));
    }

    public function test_repository_throw_logs_warning_with_exception_context(): void
    {
        $boom = new RuntimeException('Redis unavailable');

        $repo = Mockery::mock(QueuePauseRepository::class);
        $repo->shouldReceive('isPaused')->once()->andThrow($boom);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'Sunset: QueuePauseGate could not reach the pause repository; failing open.',
                ['exception' => $boom],
            );

        $gate = new QueuePauseGate($repo, $logger, $this->fixedClock(1000.0));

        $this->assertFalse($gate->isPaused('redis', 'default'));
    }

    public function test_two_throws_within_window_log_warning_only_once(): void
    {
        $now = 1000.0;

        $repo = Mockery::mock(QueuePauseRepository::class);
        $repo->shouldReceive('isPaused')
            ->twice()
            ->andThrow(new RuntimeException('Redis unavailable'));

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();

        $gate = new QueuePauseGate($repo, $logger, $this->refClock($now));

        $this->assertFalse($gate->isPaused('redis', 'default'));

        // 30 seconds later — still inside the 60s throttle window.
        $now += 30.0;
        $this->assertFalse($gate->isPaused('redis', 'default'));
    }

    public function test_throw_after_window_elapses_logs_warning_again(): void
    {
        $now = 1000.0;

        $repo = Mockery::mock(QueuePauseRepository::class);
        $repo->shouldReceive('isPaused')
            ->twice()
            ->andThrow(new RuntimeException('Redis unavailable'));

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->twice();

        $gate = new QueuePauseGate($repo, $logger, $this->refClock($now));

        $this->assertFalse($gate->isPaused('redis', 'default'));

        // Step exactly to the boundary — `>=` makes this a fresh window.
        $now += 60.0;
        $this->assertFalse($gate->isPaused('redis', 'default'));
    }

    public function test_no_extra_warning_when_window_elapses_but_no_new_throw(): void
    {
        $now = 1000.0;

        $repo = Mockery::mock(QueuePauseRepository::class);
        $repo->shouldReceive('isPaused')
            ->once()
            ->ordered()
            ->andThrow(new RuntimeException('Redis unavailable'));
        $repo->shouldReceive('isPaused')
            ->once()
            ->ordered()
            ->andReturnFalse();

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();

        $gate = new QueuePauseGate($repo, $logger, $this->refClock($now));

        $this->assertFalse($gate->isPaused('redis', 'default'));

        // Window long since elapsed, but the next call succeeds — no warning.
        $now += 600.0;
        $this->assertFalse($gate->isPaused('redis', 'default'));
    }

    public function test_warn_interval_seconds_defaults_to_sixty(): void
    {
        $now = 1000.0;

        $repo = Mockery::mock(QueuePauseRepository::class);
        $repo->shouldReceive('isPaused')
            ->times(3)
            ->andThrow(new RuntimeException('Redis unavailable'));

        $logger = Mockery::mock(LoggerInterface::class);
        // Three throws: at t=0 (logged), t=59 (suppressed), t=60 (logged).
        // Confirms the default window is exactly 60s.
        $logger->shouldReceive('warning')->twice();

        $gate = new QueuePauseGate($repo, $logger, $this->refClock($now));

        $this->assertFalse($gate->isPaused('redis', 'default'));

        $now += 59.0;
        $this->assertFalse($gate->isPaused('redis', 'default'));

        $now += 1.0;
        $this->assertFalse($gate->isPaused('redis', 'default'));
    }

    private function fixedClock(float $value): \Closure
    {
        return static fn (): float => $value;
    }

    private function refClock(float &$ref): \Closure
    {
        return static function () use (&$ref): float {
            return $ref;
        };
    }
}
