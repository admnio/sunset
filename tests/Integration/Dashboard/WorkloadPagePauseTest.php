<?php

namespace Admnio\Sunset\Tests\Integration\Dashboard;

use Admnio\Sunset\Contracts\QueuePauseRepository;
use Admnio\Sunset\Events\QueuePaused;
use Admnio\Sunset\Events\QueueResumed;
use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Event;

/**
 * v1.3.0 — Workload dashboard surface for the per-queue pause/resume control.
 *
 * Drives the page two ways:
 *   1. GET `/sunset/workload` (both the Inertia render path and the
 *      ?refresh=1 polling path) must expose a `paused_queues` prop, sourced
 *      from QueuePauseRepository::all(). Empty when nothing is paused; shape
 *      `list<array{connection, queue}>` when populated. The polling path
 *      MUST agree with the Inertia path on top-level keys (also covered by
 *      PollingShapeContractTest at the suite level — this test pins the
 *      specific key for the workload page).
 *   2. POST `/sunset/workload/{connection}/{queue}/pause` and `.../resume`
 *      flip the repository state, fire the corresponding event with
 *      actor === 'dashboard', and redirect back (302) so the Inertia client
 *      refreshes the page.
 */
class WorkloadPagePauseTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Manager::flushAuth();
        Sunset::auth(fn () => true);

        // Wipe Sunset state so each test starts from a known baseline. phpredis
        // returns keys WITH the connection prefix already applied; strip it
        // before passing back to del() (which re-applies the prefix), mirroring
        // the pattern used across the rest of the Redis-touching test suite.
        $conn = $this->app->make(RedisFactory::class)
            ->connection(config('sunset.redis_connection', 'default'));
        foreach ((array) $conn->keys('sunset:*') as $key) {
            $name = str_replace($conn->_prefix(''), '', $key);
            $conn->del($name);
        }
    }

    public function test_refresh_branch_includes_paused_queues_key_when_empty(): void
    {
        $response = $this->getJson('/sunset/workload?refresh=1');

        $response->assertStatus(200);

        $props = $response->json('props');
        $this->assertIsArray($props);
        $this->assertArrayHasKey('paused_queues', $props);
        $this->assertSame([], $props['paused_queues']);
    }

    public function test_inertia_render_branch_includes_paused_queues_key(): void
    {
        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->getJson('/sunset/workload');

        $response->assertStatus(200);

        $props = $response->json('props');
        $this->assertIsArray($props);
        $this->assertArrayHasKey(
            'paused_queues',
            $props,
            'Initial Inertia render must surface paused_queues so the page can mount with the right state.',
        );
    }

    public function test_paused_queue_appears_in_paused_queues_prop(): void
    {
        $repo = $this->app->make(QueuePauseRepository::class);
        $repo->pause('redis', 'default');

        $response = $this->getJson('/sunset/workload?refresh=1');

        $response->assertStatus(200);

        $this->assertSame(
            [['connection' => 'redis', 'queue' => 'default']],
            $response->json('props.paused_queues'),
        );
    }

    public function test_post_pause_writes_through_to_repository_and_redirects(): void
    {
        $repo = $this->app->make(QueuePauseRepository::class);
        $this->assertFalse($repo->isPaused('redis', 'default'), 'precondition: not paused');

        $response = $this->post('/sunset/workload/redis/default/pause');

        $response->assertStatus(302);
        $this->assertTrue($repo->isPaused('redis', 'default'));
    }

    public function test_post_pause_dispatches_event_with_dashboard_actor(): void
    {
        Event::fake([QueuePaused::class]);

        $this->post('/sunset/workload/redis/default/pause');

        Event::assertDispatched(
            QueuePaused::class,
            fn (QueuePaused $e) => $e->connection === 'redis'
                && $e->queue === 'default'
                && $e->actor === 'dashboard',
        );
    }

    public function test_post_resume_writes_through_to_repository_and_redirects(): void
    {
        $repo = $this->app->make(QueuePauseRepository::class);
        $repo->pause('redis', 'default');
        $this->assertTrue($repo->isPaused('redis', 'default'), 'precondition: paused');

        $response = $this->post('/sunset/workload/redis/default/resume');

        $response->assertStatus(302);
        $this->assertFalse($repo->isPaused('redis', 'default'));
    }

    public function test_post_resume_dispatches_event_with_dashboard_actor(): void
    {
        Event::fake([QueueResumed::class]);

        $this->post('/sunset/workload/redis/default/resume');

        Event::assertDispatched(
            QueueResumed::class,
            fn (QueueResumed $e) => $e->connection === 'redis'
                && $e->queue === 'default'
                && $e->actor === 'dashboard',
        );
    }
}
