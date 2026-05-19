<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetSupervisorStatusCommand;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Console\Application as Artisan;
use Mockery;

class SunsetSupervisorStatusCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $command = new SunsetSupervisorStatusCommand();

        $this->assertSame('sunset:supervisor-status', $command->getName());
    }

    public function test_command_has_name_argument(): void
    {
        $command = new SunsetSupervisorStatusCommand();

        $this->assertTrue($command->getDefinition()->hasArgument('name'));
    }

    public function test_command_description_is_set(): void
    {
        $command = new SunsetSupervisorStatusCommand();

        $this->assertNotEmpty($command->getDescription());
    }

    public function test_handle_returns_one_when_supervisor_not_found(): void
    {
        $repo = Mockery::mock(SupervisorRepository::class);
        $repo->shouldReceive('all')->once()->andReturn([]);

        $this->app->instance(SupervisorRepository::class, $repo);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetSupervisorStatusCommand::class]);
        });

        $this->artisan('sunset:supervisor-status', ['name' => 'nonexistent'])
             ->assertExitCode(1);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
