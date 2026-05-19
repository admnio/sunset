<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetSupervisorsCommand;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Console\Application as Artisan;
use Mockery;

class SunsetSupervisorsCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $command = new SunsetSupervisorsCommand();

        $this->assertSame('sunset:supervisors', $command->getName());
    }

    public function test_command_description_is_set(): void
    {
        $command = new SunsetSupervisorsCommand();

        $this->assertNotEmpty($command->getDescription());
    }

    public function test_handle_shows_no_supervisors_message_when_list_is_empty(): void
    {
        $repo = Mockery::mock(SupervisorRepository::class);
        $repo->shouldReceive('all')->once()->andReturn([]);

        $this->app->instance(SupervisorRepository::class, $repo);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetSupervisorsCommand::class]);
        });

        $this->artisan('sunset:supervisors')->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
