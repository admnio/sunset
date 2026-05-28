<?php

namespace Admnio\Sunset\Tests\Unit;

use Admnio\Sunset\Tests\TestCase;

class SunsetServiceProviderTest extends TestCase
{
    public function test_publishes_config(): void
    {
        $this->assertSame('default', config('sunset.redis_connection'));
        $this->assertSame(5, config('sunset.workload_cache_ttl'));
    }

    public function test_workload_repository_contract_resolves_to_sunset_implementation(): void
    {
        $resolved = $this->app->make(\Admnio\Sunset\Contracts\WorkloadRepository::class);

        $this->assertInstanceOf(
            \Admnio\Sunset\Repositories\SunsetWorkloadRepository::class,
            $resolved
        );
    }

    public function test_sqs_driver_resolves_to_sqs_queue(): void
    {
        $queue = $this->app['queue']->connection('sqs');

        $this->assertInstanceOf(
            \Admnio\Sunset\Transports\Sqs\SqsQueue::class,
            $queue
        );
    }

    public function test_redis_driver_resolves_to_sunset_redis_queue(): void
    {
        config([
            'queue.connections.redis' => [
                'driver' => 'redis',
                'connection' => 'default',
                'queue' => 'default',
                'retry_after' => 60,
            ],
        ]);

        $queue = $this->app['queue']->connection('redis');

        $this->assertInstanceOf(
            \Admnio\Sunset\Transports\Redis\RedisQueue::class,
            $queue
        );
    }

    public function test_artisan_command_registered(): void
    {
        $commands = array_keys($this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all());
        $this->assertContains('sunset:sweep-delayed', $commands);
    }

    public function test_validates_redis_connection_config(): void
    {
        config(['sunset.redis_connection' => 'nonexistent-connection']);
        config(['database.redis.nonexistent-connection' => null]);

        $this->expectException(\Admnio\Sunset\Exceptions\InvalidConfigurationException::class);
        $this->app->make(\Admnio\Sunset\Transports\Sqs\SqsConnector::class)
            ->connect(config('queue.connections.sqs'));
    }

    public function test_sunset_contracts_resolve_to_redis_implementations(): void
    {
        $this->assertInstanceOf(
            \Admnio\Sunset\Repositories\Redis\RedisJobRepository::class,
            $this->app->make(\Admnio\Sunset\Contracts\JobRepository::class)
        );
        $this->assertInstanceOf(
            \Admnio\Sunset\Repositories\Redis\RedisFailedJobRepository::class,
            $this->app->make(\Admnio\Sunset\Contracts\FailedJobRepository::class)
        );
        $this->assertInstanceOf(
            \Admnio\Sunset\Repositories\Redis\RedisTagRepository::class,
            $this->app->make(\Admnio\Sunset\Contracts\TagRepository::class)
        );
        $this->assertInstanceOf(
            \Admnio\Sunset\Repositories\Redis\RedisMetricsRepository::class,
            $this->app->make(\Admnio\Sunset\Contracts\MetricsRepository::class)
        );
    }

    public function test_sunset_listeners_are_registered_for_each_sunset_event(): void
    {
        $events = $this->app['events'];
        $expected = [
            \Admnio\Sunset\Events\JobQueueing::class => 2,
            \Admnio\Sunset\Events\JobQueued::class => 1,
            \Admnio\Sunset\Events\JobReserved::class => 1,
            \Admnio\Sunset\Events\JobReleased::class => 1,
            \Admnio\Sunset\Events\JobCompleted::class => 1,
            \Admnio\Sunset\Events\JobFailed::class => 1,
        ];
        foreach ($expected as $eventClass => $minListeners) {
            $count = count($events->getListeners($eventClass));
            $this->assertGreaterThanOrEqual($minListeners, $count,
                "{$eventClass} should have at least {$minListeners} listener(s), has {$count}");
        }
    }

    public function test_sunset_does_not_auto_register_maintenance_schedules(): void
    {
        // Sunset deliberately leaves cron registration to the consumer's
        // routes/console.php (or App\Console\Kernel) so scheduling is
        // explicit and visible. Guards against accidentally reintroducing
        // auto-registration in the service provider.
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $names = collect($schedule->events())->map(fn ($e) => $e->description)->all();

        foreach (['sunset-sweep-delayed', 'sunset-snapshot', 'sunset-sweep-rate-limit-slots', 'sunset-sweep-worker-metrics'] as $name) {
            $this->assertNotContains($name, $names, "Sunset should not auto-register {$name}");
        }
    }

    public function test_sunset_supervisor_contracts_resolve_to_redis_implementations(): void
    {
        $this->assertInstanceOf(
            \Admnio\Sunset\Repositories\Redis\RedisMasterSupervisorRepository::class,
            $this->app->make(\Admnio\Sunset\Contracts\MasterSupervisorRepository::class)
        );
        $this->assertInstanceOf(
            \Admnio\Sunset\Repositories\Redis\RedisSupervisorRepository::class,
            $this->app->make(\Admnio\Sunset\Contracts\SupervisorRepository::class)
        );
        $this->assertInstanceOf(
            \Admnio\Sunset\Repositories\Redis\RedisProcessRepository::class,
            $this->app->make(\Admnio\Sunset\Contracts\ProcessRepository::class)
        );
        $this->assertInstanceOf(
            \Admnio\Sunset\Repositories\Redis\RedisSupervisorCommandQueue::class,
            $this->app->make(\Admnio\Sunset\Contracts\SupervisorCommandQueue::class)
        );
    }

    public function test_all_19_v050_commands_are_registered(): void
    {
        $artisan = $this->app[\Illuminate\Contracts\Console\Kernel::class];
        $expected = [
            'sunset:work', 'sunset:supervise', 'sunset:worker',
            'sunset:pause', 'sunset:continue',
            'sunset:pause-supervisor', 'sunset:continue-supervisor',
            'sunset:status', 'sunset:supervisors', 'sunset:supervisor-status',
            'sunset:terminate',
            'sunset:clear', 'sunset:purge', 'sunset:snapshot', 'sunset:forget-failed',
            'sunset:install', 'sunset:publish',
            'sunset:migrate-horizon-config',
        ];
        $registered = array_keys($artisan->all());
        foreach ($expected as $cmd) {
            $this->assertContains($cmd, $registered, "Expected {$cmd} to be registered");
        }
    }

    public function test_horizon_artisan_command_is_overridden_by_sunset_stub(): void
    {
        $artisan = $this->app[\Illuminate\Contracts\Console\Kernel::class];
        $all = $artisan->all();
        $this->assertArrayHasKey('horizon', $all);
        $this->assertInstanceOf(
            \Admnio\Sunset\Console\SunsetHorizonRemovedCommand::class,
            $all['horizon']
        );
    }
}
