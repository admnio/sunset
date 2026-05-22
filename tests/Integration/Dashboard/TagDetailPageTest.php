<?php

namespace Admnio\Sunset\Tests\Integration\Dashboard;

use Admnio\Sunset\Contracts\TagRepository;
use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Covers GET /sunset/monitoring/tags/{tag} — the Inertia TagDetail page
 * introduced in v2.0.
 */
class TagDetailPageTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Manager::flushAuth();
        Sunset::auth(fn () => true);

        $this->app->make(RedisFactory::class)
            ->connection(config('sunset.redis_connection', 'default'))
            ->flushdb();
    }

    public function test_tag_detail_route_renders_inertia_component_with_expected_props(): void
    {
        // Pin the tag so the page reflects pinned=true.
        $repo = $this->app->make(TagRepository::class);
        $repo->monitor('tenant:acme');

        $response = $this->getJson('/sunset/monitoring/tags/' . urlencode('tenant:acme') . '?refresh=1');

        $response->assertStatus(200);

        $props = $response->json('props');
        $this->assertSame('tenant:acme', $props['tag']);

        foreach (['stats', 'classes', 'recent_runs', 'activity_series'] as $key) {
            $this->assertArrayHasKey($key, $props, "Missing prop key: {$key}");
        }

        $stats = $props['stats'];
        foreach (['total_seen', 'in_last_hour', 'last_seen_at', 'classes_count', 'failed', 'pinned'] as $k) {
            $this->assertArrayHasKey($k, $stats);
        }
        $this->assertTrue($stats['pinned'], 'Pinned tag should report pinned=true');
    }

    public function test_tag_detail_route_handles_unknown_tag_with_zero_stats(): void
    {
        $response = $this->getJson('/sunset/monitoring/tags/' . urlencode('never:seen') . '?refresh=1');

        $response->assertStatus(200);
        $this->assertSame('never:seen', $response->json('props.tag'));
        $this->assertSame(0, $response->json('props.stats.total_seen'));
        $this->assertFalse($response->json('props.stats.pinned'));
        $this->assertSame([], $response->json('props.classes'));
    }
}
