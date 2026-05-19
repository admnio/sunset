<?php

namespace Admnio\Sunset\Tests\Unit\Supervisor;

use Admnio\Sunset\Supervisor\ProcessPool;
use Admnio\Sunset\Supervisor\SupervisorOptions;
use Admnio\Sunset\Supervisor\WorkerProcess;
use Admnio\Sunset\Tests\TestCase;
use Mockery;
use Symfony\Component\Process\Process;

class ProcessPoolTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_constructor_accepts_options_and_output_callback(): void
    {
        $options = new SupervisorOptions('s', 'sqs', 'default');
        $output = fn ($type, $line) => null;
        $pool = new ProcessPool($options, $output);

        $this->assertSame($options, $pool->options);
        $this->assertSame($output, $pool->output);
    }

    public function test_constructor_uses_noop_when_no_output_callback(): void
    {
        $options = new SupervisorOptions('s', 'sqs', 'default');
        $pool = new ProcessPool($options);

        $this->assertIsCallable($pool->output);
    }

    public function test_scale_does_nothing_when_count_unchanged(): void
    {
        $pool = $this->makePool();
        $this->assertCount(0, $pool->processes);

        $pool->scale(0);

        $this->assertCount(0, $pool->processes);
    }

    public function test_scale_up_adds_worker_processes(): void
    {
        $pool = $this->makePool();
        $pool->scale(3);

        $this->assertCount(3, $pool->processes);
    }

    public function test_scale_down_moves_processes_to_terminating(): void
    {
        $pool = $this->makePool();
        $pool->scale(3);
        $pool->scale(1);

        // 2 processes moved to terminating, 1 remains active
        $this->assertCount(1, $pool->processes);
    }

    public function test_count_returns_number_of_active_processes(): void
    {
        $pool = $this->makePool();
        $pool->scale(4);

        $this->assertSame(4, count($pool));
    }

    public function test_queue_returns_options_queue(): void
    {
        $pool = $this->makePool('orders,emails');

        $this->assertSame('orders,emails', $pool->queue());
    }

    public function test_processes_returns_collection_of_active_processes(): void
    {
        $pool = $this->makePool();
        $pool->scale(2);

        $processes = $pool->processes();

        $this->assertCount(2, $processes);
    }

    public function test_pause_sets_working_to_false(): void
    {
        $pool = $this->makePool();
        $this->assertTrue($pool->working);

        $pool->pause();

        $this->assertFalse($pool->working);
    }

    public function test_continue_sets_working_to_true(): void
    {
        $pool = $this->makePool();
        $pool->working = false;

        $pool->continue();

        $this->assertTrue($pool->working);
    }

    public function test_total_process_count_includes_terminating(): void
    {
        $pool = $this->makePool();
        $pool->scale(3);
        $pool->scale(1);

        // 1 active + 2 terminating = 3 total
        $this->assertSame(3, $pool->totalProcessCount());
    }

    public function test_restart_terminates_and_restarts_same_count(): void
    {
        $pool = $this->makePool();
        $pool->scale(2);
        $pool->restart();

        // After restart: old 2 are in terminating, 2 new are active
        $this->assertSame(2, count($pool));
    }

    /**
     * Build a ProcessPool backed by mock WorkerProcess instances so no real
     * subprocess is ever spawned in the unit test.
     */
    private function makePool(string $queue = 'default'): ProcessPool
    {
        $options = new SupervisorOptions('s', 'sqs', $queue);

        // Override createProcess to return a mock WorkerProcess.
        return new class($options) extends ProcessPool {
            protected function createProcess(): WorkerProcess
            {
                $process = Mockery::mock(Process::class);
                $process->shouldReceive('start')->andReturn(null)->byDefault();
                $process->shouldReceive('signal')->andReturn(true)->byDefault();
                $process->shouldReceive('isRunning')->andReturn(false)->byDefault();
                $process->shouldReceive('stop')->andReturn(0)->byDefault();
                $process->shouldReceive('getExitCode')->andReturn(0)->byDefault();

                $wp = new WorkerProcess($process);

                return $wp;
            }
        };
    }
}
