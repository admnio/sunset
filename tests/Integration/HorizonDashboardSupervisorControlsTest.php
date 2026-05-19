<?php

namespace Admnio\Sunset\Tests\Integration;

use Admnio\Sunset\Contracts\SupervisorCommandQueue as SunsetCommandQueue;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Horizon\Contracts\HorizonCommandQueue;

class HorizonDashboardSupervisorControlsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clean up the test queue from any previous run.
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');
        $conn->del('sunset:commands:test-queue-name');
    }

    /**
     * Verify that pushing a command via the Horizon dashboard contract (HorizonCommandQueue)
     * routes through our adapter and becomes visible via Sunset's own SupervisorCommandQueue.
     *
     * This test does NOT spawn any process — it verifies the binding + delegation chain works
     * end-to-end through Redis, and is safe to run on any platform.
     */
    public function test_horizon_dashboard_pause_routes_through_adapter(): void
    {
        // 1. Resolve HorizonCommandQueue — should get our adapter.
        $horizonQueue = $this->app->make(HorizonCommandQueue::class);
        $this->assertInstanceOf(
            \Admnio\Sunset\Adapters\Horizon\HorizonSupervisorCommandQueueAdapter::class,
            $horizonQueue,
            'HorizonCommandQueue should resolve to HorizonSupervisorCommandQueueAdapter'
        );

        // 2. Push a Pause command via the Horizon dashboard contract.
        $horizonQueue->push('test-queue-name', \Laravel\Horizon\SupervisorCommands\Pause::class, []);

        // 3. Resolve SupervisorCommandQueue — should get our Redis implementation.
        $sunsetQueue = $this->app->make(SunsetCommandQueue::class);
        $this->assertInstanceOf(
            \Admnio\Sunset\Repositories\Redis\RedisSupervisorCommandQueue::class,
            $sunsetQueue,
            'SupervisorCommandQueue should resolve to RedisSupervisorCommandQueue'
        );

        // 4. Retrieve pending commands from Sunset's queue.
        $pending = $sunsetQueue->pending('test-queue-name');

        $this->assertNotEmpty($pending, 'There should be at least one pending command after push');

        // 5. Assert the pending command's class is a recognised Pause command class.
        $commandClass = $pending[0]->command ?? null;
        $this->assertNotNull($commandClass, 'Pending command should have a command class');

        $acceptableClasses = [
            \Laravel\Horizon\SupervisorCommands\Pause::class,
            \Admnio\Sunset\SupervisorCommands\Pause::class,
        ];
        $this->assertContains(
            $commandClass,
            $acceptableClasses,
            "Pending command class '{$commandClass}' should be one of the recognised Pause command classes"
        );

        // 6. Cleanup: flush the test queue.
        $sunsetQueue->flush('test-queue-name');

        $afterFlush = $sunsetQueue->pending('test-queue-name');
        $this->assertEmpty($afterFlush, 'Queue should be empty after flush');
    }
}
