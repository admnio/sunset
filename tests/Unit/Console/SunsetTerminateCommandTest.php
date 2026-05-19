<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetTerminateCommand;
use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Console\Application as Artisan;
use Mockery;

class SunsetTerminateCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $command = new SunsetTerminateCommand();

        $this->assertSame('sunset:terminate', $command->getName());
    }

    public function test_command_has_wait_option(): void
    {
        $command = new SunsetTerminateCommand();

        $this->assertTrue($command->getDefinition()->hasOption('wait'));
    }

    public function test_command_description_mentions_terminate(): void
    {
        $command = new SunsetTerminateCommand();

        $this->assertStringContainsStringIgnoringCase('terminate', $command->getDescription());
    }

    public function test_handle_shows_no_processes_when_no_masters_running(): void
    {
        $repo = Mockery::mock(MasterSupervisorRepository::class);
        $repo->shouldReceive('all')->once()->andReturn([]);

        $this->app->instance(MasterSupervisorRepository::class, $repo);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetTerminateCommand::class]);
        });

        $this->artisan('sunset:terminate')->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
