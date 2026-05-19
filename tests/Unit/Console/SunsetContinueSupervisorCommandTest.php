<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetContinueSupervisorCommand;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Console\Application as Artisan;
use Mockery;

class SunsetContinueSupervisorCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $command = new SunsetContinueSupervisorCommand();

        $this->assertSame('sunset:continue-supervisor', $command->getName());
    }

    public function test_command_has_name_argument(): void
    {
        $command = new SunsetContinueSupervisorCommand();

        $this->assertTrue($command->getDefinition()->hasArgument('name'));
    }

    public function test_command_description_is_set(): void
    {
        $command = new SunsetContinueSupervisorCommand();

        $this->assertNotEmpty($command->getDescription());
    }

    public function test_handle_returns_error_when_supervisor_not_found(): void
    {
        $repo = Mockery::mock(SupervisorRepository::class);
        $repo->shouldReceive('all')->once()->andReturn([]);

        $this->app->instance(SupervisorRepository::class, $repo);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetContinueSupervisorCommand::class]);
        });

        $this->artisan('sunset:continue-supervisor', ['name' => 'nonexistent'])
             ->assertExitCode(1);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
