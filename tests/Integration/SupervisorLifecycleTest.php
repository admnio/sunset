<?php

namespace Admnio\Sunset\Tests\Integration;

use Admnio\Sunset\Tests\Fixtures\Jobs\RecordingJob;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Symfony\Component\Process\Process;

class SupervisorLifecycleTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Supervisor process tree is Linux-only');
        }
    }

    /**
     * Spin up `sunset:work`, push jobs, wait for completion, send SIGTERM, assert clean exit.
     *
     * This test is intentionally intricate — it exercises the full supervisor process tree
     * from spawn to graceful drain. All assertions are against observable side-effects
     * (Redis zset counts, process exit code, absence of orphan keys) so the test doesn't
     * couple to internal implementation details.
     */
    public function test_full_supervisor_tree_starts_processes_and_drains_on_sigterm(): void
    {
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');

        // Clean up from any previous run.
        foreach ($conn->keys('sunset:supervisor:*') as $key) {
            $name = str_replace($conn->_prefix(''), '', $key);
            $conn->del($name);
        }
        foreach ($conn->keys('sunset:completed_jobs') as $key) {
            $name = str_replace($conn->_prefix(''), '', $key);
            $conn->del($name);
        }

        config([
            'sunset.supervisors.testing.test-supervisor' => [
                'connection' => 'redis',
                'queue' => ['supervisor-lifecycle-test'],
                'balance' => 'off',
                'processes' => 2,
                'tries' => 3,
                'timeout' => 60,
            ],
            'queue.connections.redis.queue' => 'supervisor-lifecycle-test',
        ]);

        // 1. Spawn the supervisor process in background.
        $process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'sunset:work',
            '--environment=testing',
        ]);
        $process->setEnv([
            'APP_ENV' => 'testing',
            'REDIS_HOST' => env('REDIS_HOST', '127.0.0.1'),
            'REDIS_PORT' => (string) env('REDIS_PORT', 6379),
        ]);
        $process->start();

        // 2. Wait ~3s for the supervisor to bootstrap and register itself in Redis.
        sleep(3);

        $this->assertTrue($process->isRunning(), 'Supervisor process should still be running after bootstrap');

        // 3. Push 5 RecordingJob instances to the queue.
        for ($i = 1; $i <= 5; $i++) {
            \Illuminate\Support\Facades\Queue::connection('redis')->push(
                new RecordingJob("lifecycle-{$i}")
            );
        }

        // 4. Poll for up to ~10s waiting for all 5 jobs to appear in sunset:completed_jobs.
        $deadline = time() + 10;
        $completed = 0;
        while (time() < $deadline) {
            $completed = (int) $conn->zcard('sunset:completed_jobs');
            if ($completed >= 5) {
                break;
            }
            sleep(1);
        }

        $this->assertGreaterThanOrEqual(5, $completed,
            "Expected 5 completed jobs in sunset:completed_jobs after processing; got {$completed}");

        // 5. Send SIGTERM to the master process for graceful shutdown.
        $pid = $process->getPid();
        $this->assertNotNull($pid, 'Supervisor process PID must be set');
        posix_kill($pid, SIGTERM);

        // 6. Wait up to ~10s for the process to exit gracefully.
        $exitDeadline = time() + 10;
        while ($process->isRunning() && time() < $exitDeadline) {
            sleep(1);
        }

        $process->stop(0);

        // 7. Assert clean exit.
        $this->assertSame(0, $process->getExitCode(),
            'Supervisor should exit cleanly with code 0 after SIGTERM drain');

        // 8. Assert no leftover orphan keys in sunset:supervisor:*:orphans.
        $orphanKeys = $conn->keys('sunset:supervisor:*:orphans');
        $this->assertEmpty($orphanKeys,
            'No orphan keys should remain after clean supervisor shutdown');
    }
}
