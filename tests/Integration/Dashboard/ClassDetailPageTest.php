<?php

namespace Admnio\Sunset\Tests\Integration\Dashboard;

use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;

/**
 * Covers GET /sunset/metrics/jobs/{name}/detail — the Inertia ClassDetail
 * page introduced in v2.0. Asserts route resolves, the right component is
 * rendered, and the props expose the stat keys the Vue page reads.
 */
class ClassDetailPageTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Manager::flushAuth();
        Sunset::auth(fn () => true);
    }

    public function test_class_detail_route_renders_inertia_component_with_expected_props(): void
    {
        // Use ?refresh=1 to hit the JSON branch directly. The Inertia render
        // branch reaches the same prop shape; testing both adds nothing here.
        $response = $this->getJson(
            '/sunset/metrics/jobs/' . urlencode('App\\Jobs\\IndexProduct') . '/detail?refresh=1',
        );

        $response->assertStatus(200);

        $props = $response->json('props');
        $this->assertIsArray($props);
        $this->assertSame('App\\Jobs\\IndexProduct', $props['class_name']);

        foreach (['stats', 'throughput_series', 'runtime_histogram', 'recent_runs', 'recent_failures'] as $key) {
            $this->assertArrayHasKey($key, $props, "Missing prop key: {$key}");
        }

        // Stats shape contract — Vue page reads each of these.
        $stats = $props['stats'];
        foreach (['runs_1h', 'avg_ms', 'p50_ms', 'p95_ms', 'p99_ms', 'failure_rate_pct', 'failures_1h'] as $k) {
            $this->assertArrayHasKey($k, $stats, "Stats missing: {$k}");
        }

        // Histogram is always 6 buckets.
        $this->assertCount(6, $props['runtime_histogram']);
        foreach ($props['runtime_histogram'] as $bucket) {
            foreach (['label', 'count', 'pct', 'danger'] as $k) {
                $this->assertArrayHasKey($k, $bucket);
            }
        }
    }

    public function test_class_detail_route_accepts_complex_class_names(): void
    {
        // PHP namespaced class names with backslashes — common in Laravel app
        // job classes like App\Jobs\Foo. URL-encoded by the dashboard router.
        $response = $this->getJson(
            '/sunset/metrics/jobs/' . urlencode('App\\Jobs\\Some\\Deeply\\Nested\\Class') . '/detail?refresh=1',
        );

        $response->assertStatus(200);
        $this->assertSame(
            'App\\Jobs\\Some\\Deeply\\Nested\\Class',
            $response->json('props.class_name'),
        );
    }
}
