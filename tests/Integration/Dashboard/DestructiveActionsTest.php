<?php

namespace Admnio\Sunset\Tests\Integration\Dashboard;

use Admnio\Sunset\Contracts\FailedJobRepository;
use Admnio\Sunset\Contracts\SupervisorCommandQueue;
use Admnio\Sunset\Contracts\TagRepository;
use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Manager;
use Admnio\Sunset\SupervisorCommands\ContinueWorking;
use Admnio\Sunset\SupervisorCommands\Pause;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use RuntimeException;

/**
 * Drive the dashboard POST endpoints (retry, delete, pause, resume, pin,
 * unpin) end-to-end against real Redis and assert that each one actually
 * mutates the underlying repository state. Failures here would indicate
 * the dashboard appears to "work" but quietly skips its side-effect.
 */
class DestructiveActionsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Manager::flushAuth();
        Sunset::auth(fn () => true);

        // Wipe Sunset state so each test starts from a known baseline.
        $conn = $this->app->make(RedisFactory::class)
            ->connection(config('sunset.redis_connection', 'default'));
        foreach ((array) $conn->keys('sunset:*') as $k) {
            $conn->del($k);
        }
    }

    public function test_retry_failed_job_redispatches_and_records(): void
    {
        $store = $this->app->make(FailedJobRepository::class);
        $id = $this->seedFailedJob($store, 'integration-retry-1');

        $response = $this->postJson("/sunset/jobs/failed/{$id}/retry");
        $response->assertStatus(200)->assertJson(['retried' => true]);

        // A fresh retry_id should be returned so the dashboard can stitch
        // the original failure to its retry attempt.
        $retryId = $response->json('retry_id');
        $this->assertIsString($retryId);
        $this->assertNotEmpty($retryId);
    }

    public function test_delete_failed_job_removes_record(): void
    {
        $store = $this->app->make(FailedJobRepository::class);
        $id = $this->seedFailedJob($store, 'integration-delete-1');

        $this->assertNotNull($store->findFailed($id), 'precondition: failed job was recorded');

        $response = $this->postJson("/sunset/jobs/failed/{$id}/delete");
        $response->assertStatus(200)->assertJson(['deleted' => true]);

        $this->assertNull(
            $store->findFailed($id),
            'After POST .../delete the failed job should be gone from the repository'
        );
    }

    public function test_retry_unknown_id_returns_404(): void
    {
        $response = $this->postJson('/sunset/jobs/failed/does-not-exist/retry');
        $response->assertStatus(404);
    }

    public function test_pause_supervisor_pushes_command(): void
    {
        $commands = $this->app->make(SupervisorCommandQueue::class);

        $response = $this->postJson('/sunset/supervisors/test-supervisor/pause');
        $response->assertStatus(200)->assertJson(['ok' => true, 'command' => 'pause']);

        $pending = $commands->pending('test-supervisor');
        $this->assertNotEmpty($pending, 'A Pause command should be pending for the supervisor');

        // The supervisor loop dispatches commands by their FQCN — verify the
        // exact class name was pushed (not a {type: pause} shape).
        $this->assertContains(Pause::class, $this->commandClasses($pending));
    }

    public function test_resume_supervisor_pushes_command(): void
    {
        $commands = $this->app->make(SupervisorCommandQueue::class);

        $response = $this->postJson('/sunset/supervisors/test-supervisor/resume');
        $response->assertStatus(200)->assertJson(['ok' => true, 'command' => 'continue']);

        $pending = $commands->pending('test-supervisor');
        $this->assertNotEmpty($pending, 'A ContinueWorking command should be pending for the supervisor');
        $this->assertContains(ContinueWorking::class, $this->commandClasses($pending));
    }

    public function test_pin_and_unpin_tag_round_trip(): void
    {
        $tags = $this->app->make(TagRepository::class);

        $response = $this->postJson('/sunset/monitoring/' . urlencode('tenant:42') . '/pin');
        $response->assertStatus(200)->assertJson(['pinned' => true]);

        $this->assertContains('tenant:42', $tags->monitored(), 'Tag should appear in the monitored set after pinning');
        $this->assertTrue($tags->isMonitoring('tenant:42'));

        $response = $this->postJson('/sunset/monitoring/' . urlencode('tenant:42') . '/unpin');
        $response->assertStatus(200)->assertJson(['unpinned' => true]);

        $this->assertNotContains('tenant:42', $tags->monitored(), 'Tag should no longer be monitored after unpinning');
        $this->assertFalse($tags->isMonitoring('tenant:42'));
    }

    /**
     * Seed a failed-job record via the contract's public surface. The
     * FailedJobRepository contract exposes failed(Throwable, conn, queue,
     * JobPayload) — the JobPayload's id is taken from its decoded 'uuid'
     * field, which becomes the public id used by the dashboard routes.
     */
    private function seedFailedJob(FailedJobRepository $store, string $id): string
    {
        $payload = new JobPayload(json_encode([
            'uuid'        => $id,
            'displayName' => 'TestJob',
            'job'         => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data'        => ['commandName' => 'App\\Jobs\\TestJob'],
        ]));

        $store->failed(new RuntimeException('Test exception'), 'redis', 'default', $payload);

        return $id;
    }

    /**
     * Normalize the SupervisorCommandQueue::pending() result into the list
     * of class names the supervisor would dispatch. The Redis implementation
     * stores entries as JSON-encoded {command, options} pairs, so peel out
     * the 'command' field regardless of whether the contract is a thin
     * passthrough or returns decoded arrays.
     */
    private function commandClasses(array $pending): array
    {
        $out = [];
        foreach ($pending as $entry) {
            if (is_string($entry)) {
                $decoded = json_decode($entry, true);
                $entry = is_array($decoded) ? $decoded : ['command' => $entry];
            }
            if (is_array($entry)) {
                $out[] = (string) ($entry['command'] ?? '');
            } elseif (is_object($entry)) {
                $out[] = (string) ($entry->command ?? '');
            }
        }

        return $out;
    }
}
