<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Console;

use MasonWorkforce\HorizonSqs\Console\SweepDelayedCommand;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobReenqueuer;
use MasonWorkforce\HorizonSqs\Tests\TestCase;
use Mockery;

class SweepDelayedCommandTest extends TestCase
{
    public function test_invokes_reenqueuer(): void
    {
        $reenqueuer = Mockery::mock(DelayedJobReenqueuer::class);
        $reenqueuer->shouldReceive('sweep')->once();
        $this->app->instance(DelayedJobReenqueuer::class, $reenqueuer);

        // Manually register the command since Batch 6 will do it via the provider
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)
            ->registerCommand(new SweepDelayedCommand());

        $this->artisan('horizon-sqs:sweep-delayed')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
