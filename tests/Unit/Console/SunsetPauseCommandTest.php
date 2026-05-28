<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetPauseCommand;
use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Console\Application as Artisan;
use Mockery;

class SunsetPauseCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $command = new SunsetPauseCommand();

        $this->assertSame('sunset:pause-master', $command->getName());
    }

    public function test_command_can_be_instantiated(): void
    {
        $command = new SunsetPauseCommand();

        $this->assertInstanceOf(SunsetPauseCommand::class, $command);
    }

    public function test_command_description_is_set(): void
    {
        $command = new SunsetPauseCommand();

        $this->assertNotEmpty($command->getDescription());
    }

    public function test_handle_calls_all_on_master_repository_and_exits_zero_when_empty(): void
    {
        $repo = Mockery::mock(MasterSupervisorRepository::class);
        $repo->shouldReceive('all')->once()->andReturn([]);

        $this->app->instance(MasterSupervisorRepository::class, $repo);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetPauseCommand::class]);
        });

        $this->artisan('sunset:pause-master')->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
