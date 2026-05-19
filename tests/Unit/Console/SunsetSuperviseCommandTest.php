<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetSuperviseCommand;
use Admnio\Sunset\Tests\TestCase;

class SunsetSuperviseCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $command = new SunsetSuperviseCommand();

        $this->assertStringContainsString('sunset:supervise', $command->getName());
    }

    public function test_command_is_hidden(): void
    {
        $command = new SunsetSuperviseCommand();

        $this->assertTrue($command->isHidden());
    }

    public function test_command_has_required_arguments(): void
    {
        $command = new SunsetSuperviseCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasArgument('name'));
        $this->assertTrue($definition->hasArgument('connection'));
    }

    public function test_command_has_balancing_options(): void
    {
        $command = new SunsetSuperviseCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('balance'));
        $this->assertTrue($definition->hasOption('max-processes'));
        $this->assertTrue($definition->hasOption('min-processes'));
        $this->assertTrue($definition->hasOption('balance-cooldown'));
        $this->assertTrue($definition->hasOption('balance-max-shift'));
    }

    public function test_command_has_worker_options(): void
    {
        $command = new SunsetSuperviseCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('queue'));
        $this->assertTrue($definition->hasOption('timeout'));
        $this->assertTrue($definition->hasOption('memory'));
        $this->assertTrue($definition->hasOption('sleep'));
        $this->assertTrue($definition->hasOption('tries'));
        $this->assertTrue($definition->hasOption('force'));
    }

    public function test_command_has_parent_id_option(): void
    {
        $command = new SunsetSuperviseCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('parent-id'));
    }

    public function test_command_has_auto_scaling_strategy_option(): void
    {
        $command = new SunsetSuperviseCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('auto-scaling-strategy'));
    }
}
