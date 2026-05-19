<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetStatusCommand;
use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Console\Application as Artisan;
use Mockery;

class SunsetStatusCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $command = new SunsetStatusCommand();

        $this->assertSame('sunset:status', $command->getName());
    }

    public function test_command_description_mentions_sunset(): void
    {
        $command = new SunsetStatusCommand();

        $this->assertStringContainsStringIgnoringCase('sunset', $command->getDescription());
    }

    public function test_handle_returns_one_when_no_masters_running(): void
    {
        $repo = Mockery::mock(MasterSupervisorRepository::class);
        $repo->shouldReceive('all')->once()->andReturn([]);

        $this->app->instance(MasterSupervisorRepository::class, $repo);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetStatusCommand::class]);
        });

        $this->artisan('sunset:status')->assertExitCode(1);
    }

    public function test_handle_returns_zero_when_master_is_running(): void
    {
        $master = (object) ['name' => 'master-1', 'status' => 'running', 'pid' => 1234];

        $repo = Mockery::mock(MasterSupervisorRepository::class);
        $repo->shouldReceive('all')->once()->andReturn([$master]);

        $this->app->instance(MasterSupervisorRepository::class, $repo);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetStatusCommand::class]);
        });

        $this->artisan('sunset:status')->assertExitCode(0);
    }

    public function test_handle_returns_zero_when_master_is_paused(): void
    {
        $master = (object) ['name' => 'master-1', 'status' => 'paused', 'pid' => 1234];

        $repo = Mockery::mock(MasterSupervisorRepository::class);
        $repo->shouldReceive('all')->once()->andReturn([$master]);

        $this->app->instance(MasterSupervisorRepository::class, $repo);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetStatusCommand::class]);
        });

        $this->artisan('sunset:status')->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
