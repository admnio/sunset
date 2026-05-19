<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetHorizonRemovedCommand;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Console\Application as Artisan;

class SunsetHorizonRemovedCommandTest extends TestCase
{
    public function test_command_has_correct_name(): void
    {
        $command = new SunsetHorizonRemovedCommand();

        $this->assertSame('horizon', $command->getName());
    }

    public function test_command_description_mentions_removed(): void
    {
        $command = new SunsetHorizonRemovedCommand();

        $this->assertStringContainsStringIgnoringCase('removed', $command->getDescription());
    }

    public function test_handle_returns_exit_code_one(): void
    {
        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetHorizonRemovedCommand::class]);
        });

        $this->artisan('horizon')->assertExitCode(1);
    }

    public function test_handle_output_mentions_removed(): void
    {
        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetHorizonRemovedCommand::class]);
        });

        $this->artisan('horizon')
            ->expectsOutputToContain('removed')
            ->assertExitCode(1);
    }
}
