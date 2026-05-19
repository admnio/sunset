<?php

namespace Admnio\Sunset\Tests\Unit\Supervisor;

use Admnio\Sunset\Contracts\Pausable;
use Admnio\Sunset\Contracts\Restartable;
use Admnio\Sunset\Contracts\Terminable;
use Admnio\Sunset\Supervisor\ListensForSignals;
use Admnio\Sunset\Tests\TestCase;

class ListensForSignalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl extension is not available (Windows host).');
        }
    }

    public function test_sigterm_queues_terminate_signal(): void
    {
        $consumer = $this->makeConsumer();
        $consumer->exposedListenForSignals();

        posix_kill(posix_getpid(), SIGTERM);
        pcntl_signal_dispatch();

        $this->assertArrayHasKey('terminate', $consumer->pendingSignals);
    }

    public function test_sigusr1_queues_restart_signal(): void
    {
        $consumer = $this->makeConsumer();
        $consumer->exposedListenForSignals();

        posix_kill(posix_getpid(), SIGUSR1);
        pcntl_signal_dispatch();

        $this->assertArrayHasKey('restart', $consumer->pendingSignals);
    }

    public function test_sigusr2_queues_pause_signal(): void
    {
        $consumer = $this->makeConsumer();
        $consumer->exposedListenForSignals();

        posix_kill(posix_getpid(), SIGUSR2);
        pcntl_signal_dispatch();

        $this->assertArrayHasKey('pause', $consumer->pendingSignals);
    }

    public function test_sigcont_queues_continue_signal(): void
    {
        $consumer = $this->makeConsumer();
        $consumer->exposedListenForSignals();

        posix_kill(posix_getpid(), SIGCONT);
        pcntl_signal_dispatch();

        $this->assertArrayHasKey('continue', $consumer->pendingSignals);
    }

    public function test_process_pending_signals_dispatches_all_queued_signals(): void
    {
        $consumer = $this->makeConsumer();
        $consumer->pendingSignals = ['pause' => 'pause', 'continue' => 'continue'];

        $consumer->exposedProcessPendingSignals();

        $this->assertSame(['pause', 'continue'], $consumer->calledMethods);
        $this->assertEmpty($consumer->pendingSignals);
    }

    public function test_process_pending_signals_clears_queue_after_dispatch(): void
    {
        $consumer = $this->makeConsumer();
        $consumer->pendingSignals = ['terminate' => 'terminate'];

        $consumer->exposedProcessPendingSignals();

        $this->assertEmpty($consumer->pendingSignals);
    }

    private function makeConsumer(): object
    {
        return new class implements Pausable, Restartable, Terminable
        {
            use ListensForSignals;

            public array $calledMethods = [];

            public function pause(): void
            {
                $this->calledMethods[] = 'pause';
            }

            public function continue(): void
            {
                $this->calledMethods[] = 'continue';
            }

            public function restart(): void
            {
                $this->calledMethods[] = 'restart';
            }

            public function terminate(int $status = 0): void
            {
                $this->calledMethods[] = 'terminate';
            }

            public function exposedListenForSignals(): void
            {
                $this->listenForSignals();
            }

            public function exposedProcessPendingSignals(): void
            {
                $this->processPendingSignals();
            }
        };
    }
}
