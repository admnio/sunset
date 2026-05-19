<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetWorkerCommand;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Queue\Console\WorkCommand as BaseWorkCommand;
use Illuminate\Queue\Worker;
use Mockery;

class SunsetWorkerCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $command = $this->makeCommand();

        $this->assertStringContainsString('sunset:worker', $command->getName());
    }

    public function test_command_extends_base_work_command(): void
    {
        $command = $this->makeCommand();

        $this->assertInstanceOf(BaseWorkCommand::class, $command);
    }

    public function test_command_is_hidden(): void
    {
        $command = $this->makeCommand();

        $this->assertTrue($command->isHidden());
    }

    public function test_command_has_supervisor_option(): void
    {
        $command = $this->makeCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('supervisor'));
    }

    public function test_command_has_standard_worker_options(): void
    {
        $command = $this->makeCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('queue'));
        $this->assertTrue($definition->hasOption('timeout'));
        $this->assertTrue($definition->hasOption('memory'));
        $this->assertTrue($definition->hasOption('sleep'));
        $this->assertTrue($definition->hasOption('tries'));
        $this->assertTrue($definition->hasOption('force'));
        $this->assertTrue($definition->hasOption('backoff'));
    }

    private function makeCommand(): SunsetWorkerCommand
    {
        $worker = Mockery::mock(Worker::class)->makePartial();
        $cache = Mockery::mock(CacheRepository::class);

        return new SunsetWorkerCommand($worker, $cache);
    }
}
