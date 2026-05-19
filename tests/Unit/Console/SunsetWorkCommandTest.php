<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetWorkCommand;
use Admnio\Sunset\Tests\TestCase;

class SunsetWorkCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $command = new SunsetWorkCommand();

        $this->assertStringContainsString('sunset:work', $command->getName());
    }

    public function test_command_can_be_instantiated(): void
    {
        $command = new SunsetWorkCommand();

        $this->assertInstanceOf(SunsetWorkCommand::class, $command);
    }

    public function test_command_has_environment_option(): void
    {
        $command = new SunsetWorkCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('environment'));
    }

    public function test_command_description_mentions_sunset(): void
    {
        $command = new SunsetWorkCommand();

        $this->assertStringContainsString('supervisor', strtolower($command->getDescription()));
    }
}
