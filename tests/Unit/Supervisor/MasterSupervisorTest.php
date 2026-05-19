<?php

namespace Admnio\Sunset\Tests\Unit\Supervisor;

use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Contracts\SupervisorCommandQueue;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Supervisor\MasterSupervisor;
use Admnio\Sunset\Supervisor\SupervisorProcess;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class MasterSupervisorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset the static token between tests so tests are isolated
        MasterSupervisor::resetToken();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Construction + static name helpers
    // -----------------------------------------------------------------------

    public function test_constructor_sets_working_and_supervisors_to_empty(): void
    {
        $this->bindDependencies();

        $master = new MasterSupervisor('production');

        $this->assertSame('production', $master->environment);
        $this->assertTrue($master->working);
        $this->assertCount(0, $master->supervisors);
    }

    public function test_constructor_flushes_command_queue_for_master_queue(): void
    {
        $queue = Mockery::mock(SupervisorCommandQueue::class);
        $queue->shouldReceive('flush')->once()->withArgs(function ($name) {
            return str_starts_with($name, 'master:');
        });

        $masterRepo = Mockery::mock(MasterSupervisorRepository::class);
        $masterRepo->shouldReceive('find')->andReturn(null)->byDefault();

        $this->app->instance(SupervisorCommandQueue::class, $queue);
        $this->app->instance(MasterSupervisorRepository::class, $masterRepo);

        new MasterSupervisor();

        $this->assertTrue(true);
    }

    public function test_name_returns_basename_plus_random_token(): void
    {
        $name = MasterSupervisor::name();

        $this->assertStringContainsString('-', $name);
        // Token is 4 chars, separated by '-' from the basename
        $parts = explode('-', $name);
        $this->assertSame(4, strlen(end($parts)));
    }

    public function test_name_is_stable_once_generated(): void
    {
        $name1 = MasterSupervisor::name();
        $name2 = MasterSupervisor::name();

        $this->assertSame($name1, $name2);
    }

    public function test_basename_can_be_overridden_with_name_resolver(): void
    {
        MasterSupervisor::determineNameUsing(fn () => 'custom-host');

        $basename = MasterSupervisor::basename();

        $this->assertSame('custom-host', $basename);

        // Cleanup
        MasterSupervisor::$nameResolver = null;
    }

    public function test_command_queue_starts_with_master_prefix(): void
    {
        $queue = MasterSupervisor::commandQueue();

        $this->assertStringStartsWith('master:', $queue);
    }

    public function test_command_queue_for_returns_master_prefix_plus_name(): void
    {
        $queue = MasterSupervisor::commandQueueFor('foo-master');

        $this->assertSame('master:foo-master', $queue);
    }

    public function test_command_queue_for_returns_current_queue_when_null(): void
    {
        $queue1 = MasterSupervisor::commandQueue();
        $queue2 = MasterSupervisor::commandQueueFor(null);

        $this->assertSame($queue1, $queue2);
    }

    // -----------------------------------------------------------------------
    // State transitions: pause / continue / restart
    // -----------------------------------------------------------------------

    public function test_pause_sets_working_to_false(): void
    {
        $master = $this->makeMaster();

        $this->assertTrue($master->working);
        $master->pause();
        $this->assertFalse($master->working);
    }

    public function test_continue_sets_working_to_true(): void
    {
        $master = $this->makeMaster();
        $master->working = false;

        $master->continue();

        $this->assertTrue($master->working);
    }

    public function test_restart_sets_working_to_true(): void
    {
        $master = $this->makeMaster();
        $master->working = false;

        $master->restart();

        $this->assertTrue($master->working);
    }

    // -----------------------------------------------------------------------
    // ensureNoOtherMasterSupervisors()
    // -----------------------------------------------------------------------

    public function test_ensure_no_other_masters_throws_when_already_running(): void
    {
        $masterRepo = Mockery::mock(MasterSupervisorRepository::class);
        // Return a non-null value (another master found)
        $masterRepo->shouldReceive('find')->andReturn(['name' => 'existing-master']);
        $masterRepo->shouldReceive('update')->byDefault();

        $queue = Mockery::mock(SupervisorCommandQueue::class);
        $queue->shouldReceive('flush')->byDefault();
        $queue->shouldReceive('pending')->andReturn([])->byDefault();

        $this->app->instance(MasterSupervisorRepository::class, $masterRepo);
        $this->app->instance(SupervisorCommandQueue::class, $queue);

        $this->expectException(\Exception::class);

        $master = new MasterSupervisor();
        $master->ensureNoOtherMasterSupervisors();
    }

    public function test_ensure_no_other_masters_passes_when_not_found(): void
    {
        $this->bindDependencies(findReturns: null);

        $master = new MasterSupervisor();
        $master->ensureNoOtherMasterSupervisors(); // should not throw

        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // persist()
    // -----------------------------------------------------------------------

    public function test_persist_calls_master_repository_update(): void
    {
        $masterRepo = Mockery::mock(MasterSupervisorRepository::class);
        $masterRepo->shouldReceive('find')->andReturn(null)->byDefault();
        $masterRepo->shouldReceive('update')->once();

        $queue = Mockery::mock(SupervisorCommandQueue::class);
        $queue->shouldReceive('flush')->byDefault();
        $queue->shouldReceive('pending')->andReturn([])->byDefault();

        $this->app->instance(MasterSupervisorRepository::class, $masterRepo);
        $this->app->instance(SupervisorCommandQueue::class, $queue);

        $master = new MasterSupervisor();
        $master->persist();

        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // monitorSupervisors() — the key loop behavior
    // -----------------------------------------------------------------------

    public function test_monitor_supervisors_removes_dead_supervisor_processes(): void
    {
        $master = $this->makeMaster();

        // Add two mock supervisor processes — one alive, one dead
        $alive = Mockery::mock(SupervisorProcess::class)->makePartial();
        $alive->dead = false;
        $alive->shouldReceive('monitor')->byDefault();

        $dead = Mockery::mock(SupervisorProcess::class)->makePartial();
        $dead->dead = true;
        $dead->shouldReceive('monitor')->byDefault();

        $master->supervisors = collect([$alive, $dead]);

        $master->exposedMonitorSupervisors();

        $this->assertCount(1, $master->supervisors);
        $this->assertFalse($master->supervisors->first()->dead);
    }

    // -----------------------------------------------------------------------
    // loop() — single iteration
    // -----------------------------------------------------------------------

    public function test_loop_dispatches_master_supervisor_looped_event(): void
    {
        $this->bindDependencies();

        $master = new MasterSupervisor();

        $fired = false;
        $this->app['events']->listen(\Admnio\Sunset\Events\MasterSupervisorLooped::class, function () use (&$fired) {
            $fired = true;
        });

        $master->loop();

        $this->assertTrue($fired, 'MasterSupervisorLooped event was not dispatched');
    }

    public function test_loop_skips_monitor_supervisors_when_paused(): void
    {
        $this->bindDependencies();

        $master = new MasterSupervisor();
        $master->pause();

        // Add a mock that should NOT be called
        $sp = Mockery::mock(SupervisorProcess::class)->makePartial();
        $sp->dead = false;
        $sp->shouldReceive('monitor')->never();

        $master->supervisors = collect([$sp]);
        $master->loop();

        $this->assertFalse($master->working);
    }

    // -----------------------------------------------------------------------
    // pid() / memoryUsage()
    // -----------------------------------------------------------------------

    public function test_pid_returns_current_process_id(): void
    {
        $this->bindDependencies();
        $master = new MasterSupervisor();

        $this->assertSame(getmypid(), $master->pid());
    }

    public function test_memory_usage_returns_positive_float(): void
    {
        $this->bindDependencies();
        $master = new MasterSupervisor();

        $this->assertGreaterThan(0.0, $master->memoryUsage());
    }

    // -----------------------------------------------------------------------
    // Output handler
    // -----------------------------------------------------------------------

    public function test_handle_output_using_sets_callback(): void
    {
        $this->bindDependencies();
        $master = new MasterSupervisor();

        $called = false;
        $master->handleOutputUsing(function ($type, $line) use (&$called) {
            $called = true;
        });

        $master->output('out', 'hello');

        $this->assertTrue($called);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function bindDependencies(?array $findReturns = null): void
    {
        $masterRepo = Mockery::mock(MasterSupervisorRepository::class);
        $masterRepo->shouldReceive('find')->andReturn($findReturns)->byDefault();
        $masterRepo->shouldReceive('update')->byDefault();

        $queue = Mockery::mock(SupervisorCommandQueue::class);
        $queue->shouldReceive('flush')->byDefault();
        $queue->shouldReceive('pending')->andReturn([])->byDefault();

        $this->app->instance(MasterSupervisorRepository::class, $masterRepo);
        $this->app->instance(SupervisorCommandQueue::class, $queue);
    }

    private function makeMaster(): object
    {
        $this->bindDependencies();

        // Expose protected monitorSupervisors for testing
        return new class extends MasterSupervisor {
            public function exposedMonitorSupervisors(): void
            {
                $this->monitorSupervisors();
            }
        };
    }
}
