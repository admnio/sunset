<?php

namespace MasonWorkforce\HorizonSqs\Tests\Integration;

use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Horizon;
use MasonWorkforce\HorizonSqs\Tests\Fixtures\Jobs\RecordingJob;

class DashboardJsonTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Tell our ServiceProvider's resolveQueueList() which queues to query
        // by registering a supervisor for the current ('testing') environment.
        $app['config']->set('horizon.environments.testing.supervisor-1', [
            'connection' => 'sqs',
            'queue' => ['default'],
        ]);

        // Horizon's routes execute through the full HTTP kernel, which boots
        // the EncryptionServiceProvider and requires an app key.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLocalStackAvailable();
        $this->deleteAllQueues();
        $url = $this->createQueue('default');
        config(['queue.connections.sqs.prefix' => str_replace('/default', '', $url)]);

        // Bypass Horizon's "viewHorizon" gate which by default only allows
        // local environment access. Without this, /horizon/api/* returns 403.
        Horizon::auth(fn () => true);
    }

    public function test_workload_endpoint_returns_sqs_backed_data(): void
    {
        Queue::push(new RecordingJob('m1'));
        Queue::push(new RecordingJob('m2'));

        sleep(1); // SQS approximate count latency

        $response = $this->get('/horizon/api/workload');

        $response->assertOk();
        $data = $response->json();

        $default = collect($data)->firstWhere('name', 'default');
        $this->assertNotNull($default);
        $this->assertGreaterThanOrEqual(2, $default['length']);
        $this->assertArrayHasKey('wait', $default);
    }
}
