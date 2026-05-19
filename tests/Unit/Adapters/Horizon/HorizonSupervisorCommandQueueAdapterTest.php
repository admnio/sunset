<?php

namespace Admnio\Sunset\Tests\Unit\Adapters\Horizon;

use Admnio\Sunset\Adapters\Horizon\HorizonSupervisorCommandQueueAdapter;
use Admnio\Sunset\Contracts\SupervisorCommandQueue as SunsetCommandQueue;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class HorizonSupervisorCommandQueueAdapterTest extends TestCase
{
    public function test_push_delegates_to_sunset_command_queue(): void
    {
        $queue = Mockery::mock(SunsetCommandQueue::class);
        $queue->shouldReceive('push')
            ->with('supervisor-1', 'SomeCommand', ['option' => 'value'])
            ->once();

        $adapter = new HorizonSupervisorCommandQueueAdapter($queue);
        $adapter->push('supervisor-1', 'SomeCommand', ['option' => 'value']);
    }

    public function test_pending_delegates_to_sunset_command_queue(): void
    {
        $queue = Mockery::mock(SunsetCommandQueue::class);
        $queue->shouldReceive('pending')
            ->with('supervisor-1')
            ->once()
            ->andReturn([['command' => 'Pause', 'options' => []]]);

        $adapter = new HorizonSupervisorCommandQueueAdapter($queue);

        $this->assertSame([['command' => 'Pause', 'options' => []]], $adapter->pending('supervisor-1'));
    }

    public function test_flush_delegates_to_sunset_command_queue(): void
    {
        $queue = Mockery::mock(SunsetCommandQueue::class);
        $queue->shouldReceive('flush')->with('supervisor-1')->once();

        $adapter = new HorizonSupervisorCommandQueueAdapter($queue);
        $adapter->flush('supervisor-1');
    }
}
