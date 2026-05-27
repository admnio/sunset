<?php

namespace Admnio\Sunset\Tests\Unit\Transports\Database;

use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Events\JobReserved;
use Admnio\Sunset\Support\TransportRegistry;
use Admnio\Sunset\Tests\Fixtures\Jobs\RecordingJob;
use Admnio\Sunset\Tests\TestCase;
use Admnio\Sunset\Transports\Database\DatabaseConnector;
use Admnio\Sunset\Transports\Database\DatabaseQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class DatabaseTransportTest extends TestCase
{
    private const CONN = 'sunset_db_test';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', self::CONN);
        $app['config']->set('database.connections.'.self::CONN, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => self::CONN,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 60,
        ]);

        $app['config']->set('sunset.transports.database', [
            'workload_connection' => self::CONN,
            'table' => 'jobs',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection(self::CONN)->create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        @unlink(sys_get_temp_dir().'/sunset-marker');
    }

    private function db()
    {
        return $this->app->make('db')->connection(self::CONN);
    }

    public function test_transport_is_registered_under_database_name(): void
    {
        $transport = $this->app->make(TransportRegistry::class)->get('database');

        $this->assertSame('database', $transport->name());
    }

    public function test_database_connection_resolves_to_sunset_queue(): void
    {
        $this->assertInstanceOf(DatabaseQueue::class, Queue::connection('database'));
    }

    public function test_connector_builds_queue_via_registry(): void
    {
        $queue = $this->app->make(DatabaseConnector::class)->connect([
            'connection' => self::CONN,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 60,
        ]);

        $this->assertInstanceOf(DatabaseQueue::class, $queue);
    }

    public function test_push_prepares_payload_and_fires_job_queued(): void
    {
        Event::fake([JobQueued::class, JobQueueing::class, JobReserved::class]);

        Queue::push(new RecordingJob('hello-db'));

        Event::assertDispatched(JobQueued::class);

        $row = $this->db()->table('jobs')->first();
        $this->assertNotNull($row, 'Expected a row inserted into the jobs table');

        $decoded = json_decode($row->payload, true);
        $this->assertArrayHasKey('tags', $decoded);
        $this->assertArrayHasKey('pushedAt', $decoded);
        $this->assertSame('job', $decoded['type']);
    }

    public function test_push_pop_fire_roundtrip_processes_job(): void
    {
        Event::fake([JobQueued::class, JobQueueing::class, JobReserved::class]);

        Queue::push(new RecordingJob('roundtrip-db'));

        $job = Queue::connection('database')->pop('default');
        $this->assertNotNull($job, 'Expected to pop the job back off the database queue');

        Event::assertDispatched(JobReserved::class);

        $job->fire();

        $this->assertSame(
            'roundtrip-db',
            file_get_contents(sys_get_temp_dir().'/sunset-marker')
        );
    }

    public function test_release_does_not_refire_job_queued(): void
    {
        Event::fake([JobQueued::class, JobQueueing::class, JobReserved::class]);

        Queue::push(new RecordingJob('release-db'));

        $job = Queue::connection('database')->pop('default');
        $this->assertNotNull($job);

        // Releasing a reserved job re-inserts it via the parent's
        // pushToDatabase() — it must NOT be treated as a fresh enqueue.
        $job->release(0);

        Event::assertDispatchedTimes(JobQueued::class, 1);
    }

    public function test_workload_counts_rows_per_queue(): void
    {
        $now = time();
        foreach (['default', 'default', 'emails'] as $queue) {
            $this->db()->table('jobs')->insert([
                'queue' => $queue,
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now,
                'created_at' => $now,
            ]);
        }

        $transport = $this->app->make(TransportRegistry::class)->get('database');

        $workload = collect($transport->workload(['default', 'emails', 'unused']))
            ->keyBy('name');

        $this->assertSame(2, $workload['default']['length']);
        $this->assertSame(1, $workload['emails']['length']);
        $this->assertSame(0, $workload['unused']['length']);
    }
}
