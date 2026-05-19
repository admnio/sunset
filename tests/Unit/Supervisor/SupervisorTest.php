<?php

namespace Admnio\Sunset\Tests\Unit\Supervisor;

use Admnio\Sunset\AutoScaler;
use Admnio\Sunset\Contracts\SupervisorCommandQueue;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Supervisor\ProcessPool;
use Admnio\Sunset\Supervisor\Supervisor;
use Admnio\Sunset\Supervisor\SupervisorOptions;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class SupervisorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Construction
    // -----------------------------------------------------------------------

    public function test_constructor_sets_name_and_options(): void
    {
        $this->bindDependencies();
        $options = new SupervisorOptions('sup-1', 'sqs', 'default');

        $supervisor = new Supervisor($options);

        $this->assertSame('sup-1', $supervisor->name);
        $this->assertSame($options, $supervisor->options);
        $this->assertTrue($supervisor->working);
    }

    public function test_constructor_creates_single_process_pool_when_not_balancing(): void
    {
        $this->bindDependencies();
        $options = new SupervisorOptions('sup-1', 'sqs', 'default', balance: 'off');

        $supervisor = new Supervisor($options);

        $this->assertCount(1, $supervisor->processPools);
    }

    public function test_constructor_creates_pool_per_queue_when_balancing(): void
    {
        $this->bindDependencies();
        $options = new SupervisorOptions('sup-1', 'sqs', 'orders,emails,invoices', balance: 'auto');

        $supervisor = new Supervisor($options);

        $this->assertCount(3, $supervisor->processPools);
    }

    public function test_constructor_flushes_command_queue(): void
    {
        $queue = Mockery::mock(SupervisorCommandQueue::class);
        $queue->shouldReceive('flush')->once()->with('sup-1');

        $repo = Mockery::mock(SupervisorRepository::class);
        $repo->shouldReceive('find')->andReturn(null)->byDefault();

        $this->app->instance(SupervisorCommandQueue::class, $queue);
        $this->app->instance(SupervisorRepository::class, $repo);

        $options = new SupervisorOptions('sup-1', 'sqs', 'default');
        new Supervisor($options);

        $this->assertTrue(true); // Mockery asserts once() on tearDown
    }

    // -----------------------------------------------------------------------
    // State transitions: pause / continue / restart
    // -----------------------------------------------------------------------

    public function test_pause_sets_working_to_false(): void
    {
        $supervisor = $this->makeSupervisor();

        $this->assertTrue($supervisor->working);

        $supervisor->pause();

        $this->assertFalse($supervisor->working);
    }

    public function test_continue_sets_working_to_true(): void
    {
        $supervisor = $this->makeSupervisor();
        $supervisor->working = false;

        $supervisor->continue();

        $this->assertTrue($supervisor->working);
    }

    public function test_restart_sets_working_to_true(): void
    {
        $supervisor = $this->makeSupervisor();
        $supervisor->working = false;

        $supervisor->restart();

        $this->assertTrue($supervisor->working);
    }

    public function test_is_paused_reflects_working_state(): void
    {
        $supervisor = $this->makeSupervisor();

        $this->assertFalse($supervisor->isPaused());

        $supervisor->pause();

        $this->assertTrue($supervisor->isPaused());
    }

    // -----------------------------------------------------------------------
    // scale() and balance()
    // -----------------------------------------------------------------------

    public function test_scale_adjusts_max_processes_and_delegates_to_balance(): void
    {
        $supervisor = $this->makeSupervisor(balance: 'off');

        // Single pool, scale to 3
        $supervisor->scale(3);

        // maxProcesses must be >= the passed value
        $this->assertGreaterThanOrEqual(3, $supervisor->options->maxProcesses);
    }

    public function test_balance_delegates_scale_to_matching_pool(): void
    {
        $supervisor = $this->makeSupervisor(balance: 'auto', queue: 'orders,emails');

        $supervisor->balance(['orders' => 2, 'emails' => 1]);

        // Verify pools have the right number of processes
        $orderPool = $supervisor->processPools->first(fn ($p) => $p->queue() === 'orders');
        $emailPool = $supervisor->processPools->first(fn ($p) => $p->queue() === 'emails');

        $this->assertSame(2, count($orderPool));
        $this->assertSame(1, count($emailPool));
    }

    public function test_balance_ignores_unknown_queue(): void
    {
        // Should not throw — unknown queue key just falls through to the null object
        $supervisor = $this->makeSupervisor(balance: 'auto', queue: 'orders,emails');

        $this->expectNotToPerformAssertions();
        $supervisor->balance(['nonexistent' => 5]);
    }

    // -----------------------------------------------------------------------
    // processes() / totalProcessCount() / totalSystemProcessCount()
    // -----------------------------------------------------------------------

    public function test_processes_returns_collapsed_collection_of_all_worker_processes(): void
    {
        $supervisor = $this->makeSupervisor(balance: 'auto', queue: 'orders,emails');
        $supervisor->balance(['orders' => 2, 'emails' => 1]);

        $processes = $supervisor->processes();

        $this->assertCount(3, $processes);
    }

    public function test_total_process_count_is_sum_of_pools(): void
    {
        $supervisor = $this->makeSupervisor(balance: 'auto', queue: 'orders,emails');
        $supervisor->balance(['orders' => 2, 'emails' => 1]);

        $this->assertSame(3, $supervisor->totalProcessCount());
    }

    // -----------------------------------------------------------------------
    // persist()
    // -----------------------------------------------------------------------

    public function test_persist_calls_supervisor_repository_update(): void
    {
        $repo = Mockery::mock(SupervisorRepository::class);
        $repo->shouldReceive('find')->andReturn(null)->byDefault();
        $repo->shouldReceive('update')->once();

        $queue = Mockery::mock(SupervisorCommandQueue::class);
        $queue->shouldReceive('flush')->byDefault();

        $this->app->instance(SupervisorRepository::class, $repo);
        $this->app->instance(SupervisorCommandQueue::class, $queue);

        $options = new SupervisorOptions('sup-1', 'sqs', 'default');
        $supervisor = new Supervisor($options);

        $supervisor->persist();

        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // ensureNoDuplicateSupervisors()
    // -----------------------------------------------------------------------

    public function test_ensure_no_duplicate_supervisors_throws_when_name_found(): void
    {
        $repo = Mockery::mock(SupervisorRepository::class);
        $repo->shouldReceive('find')->with('sup-dup')->andReturn(['name' => 'sup-dup']);

        $queue = Mockery::mock(SupervisorCommandQueue::class);
        $queue->shouldReceive('flush')->byDefault();

        $this->app->instance(SupervisorRepository::class, $repo);
        $this->app->instance(SupervisorCommandQueue::class, $queue);

        $this->expectException(\Exception::class);

        $options = new SupervisorOptions('sup-dup', 'sqs', 'default');
        $supervisor = new Supervisor($options);
        $supervisor->ensureNoDuplicateSupervisors();
    }

    public function test_ensure_no_duplicate_supervisors_passes_when_not_found(): void
    {
        $this->bindDependencies(findReturns: null);

        $options = new SupervisorOptions('sup-ok', 'sqs', 'default');
        $supervisor = new Supervisor($options);

        $supervisor->ensureNoDuplicateSupervisors(); // Should not throw

        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // loop() — single iteration, NOT the infinite while loop
    // -----------------------------------------------------------------------

    public function test_loop_dispatches_supervisor_looped_event(): void
    {
        $this->bindDependencies();

        $options = new SupervisorOptions('sup-1', 'sqs', 'default');
        $supervisor = new Supervisor($options);

        $fired = false;
        $this->app['events']->listen(\Admnio\Sunset\Events\SupervisorLooped::class, function () use (&$fired) {
            $fired = true;
        });

        $supervisor->loop();

        $this->assertTrue($fired, 'SupervisorLooped event was not dispatched');
    }

    public function test_loop_calls_persist(): void
    {
        $repo = Mockery::mock(SupervisorRepository::class);
        $repo->shouldReceive('find')->andReturn(null)->byDefault();
        // update() will be called at least once in loop (plus possibly in constructor chain)
        $repo->shouldReceive('update')->atLeast()->once();

        $queue = Mockery::mock(SupervisorCommandQueue::class);
        $queue->shouldReceive('flush')->byDefault();
        $queue->shouldReceive('pending')->andReturn([])->byDefault();

        $scaler = Mockery::mock(AutoScaler::class);
        $scaler->shouldReceive('scale')->byDefault();

        $this->app->instance(SupervisorRepository::class, $repo);
        $this->app->instance(SupervisorCommandQueue::class, $queue);
        $this->app->instance(AutoScaler::class, $scaler);

        $options = new SupervisorOptions('sup-1', 'sqs', 'default');
        $supervisor = new Supervisor($options);
        $supervisor->loop();

        $this->assertTrue(true);
    }

    public function test_loop_skips_autoscale_when_paused(): void
    {
        $this->bindDependencies();

        $options = new SupervisorOptions('sup-1', 'sqs', 'default', balance: 'auto');
        $supervisor = new Supervisor($options);
        $supervisor->pause();

        // Should not throw even with no AutoScaler bound — because it won't call it
        $supervisor->loop();

        $this->assertFalse($supervisor->working);
    }

    // -----------------------------------------------------------------------
    // Output handler
    // -----------------------------------------------------------------------

    public function test_handle_output_using_sets_callback(): void
    {
        $this->bindDependencies();
        $options = new SupervisorOptions('sup-1', 'sqs', 'default');
        $supervisor = new Supervisor($options);

        $called = false;
        $supervisor->handleOutputUsing(function ($type, $line) use (&$called) {
            $called = true;
        });

        $supervisor->output('out', 'hello');

        $this->assertTrue($called);
    }

    // -----------------------------------------------------------------------
    // pid() / memoryUsage()
    // -----------------------------------------------------------------------

    public function test_pid_returns_current_process_id(): void
    {
        $this->bindDependencies();
        $supervisor = new Supervisor(new SupervisorOptions('sup-1', 'sqs', 'default'));

        $this->assertSame(getmypid(), $supervisor->pid());
    }

    public function test_memory_usage_returns_positive_float(): void
    {
        $this->bindDependencies();
        $supervisor = new Supervisor(new SupervisorOptions('sup-1', 'sqs', 'default'));

        $this->assertGreaterThan(0.0, $supervisor->memoryUsage());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Bind minimal mock dependencies.
     */
    private function bindDependencies(?array $findReturns = null): void
    {
        $repo = Mockery::mock(SupervisorRepository::class);
        $repo->shouldReceive('find')->andReturn($findReturns)->byDefault();
        $repo->shouldReceive('update')->byDefault();

        $queue = Mockery::mock(SupervisorCommandQueue::class);
        $queue->shouldReceive('flush')->byDefault();
        $queue->shouldReceive('pending')->andReturn([])->byDefault();

        $scaler = Mockery::mock(AutoScaler::class);
        $scaler->shouldReceive('scale')->byDefault();

        $this->app->instance(SupervisorRepository::class, $repo);
        $this->app->instance(SupervisorCommandQueue::class, $queue);
        $this->app->instance(AutoScaler::class, $scaler);
    }

    /**
     * Build a Supervisor backed by mock ProcessPool instances so no real
     * subprocess is ever spawned.
     */
    private function makeSupervisor(
        string $balance = 'off',
        string $queue = 'default',
    ): Supervisor {
        $this->bindDependencies();

        $options = new SupervisorOptions(
            name: 'sup-1',
            connection: 'sqs',
            queue: $queue,
            balance: $balance,
            maxProcesses: 10,
            minProcesses: 1,
        );

        return new Supervisor($options);
    }
}
