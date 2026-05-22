<?php

namespace Admnio\Sunset\Tests\Integration\Dashboard;

use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * The HealthStrip reads `page.props.sunset.health.*` on every dashboard page.
 * That payload is shared by the SetSunsetInertiaRoot middleware via
 * Inertia::share(). When the dashboard is hit via Inertia's XHR path, the
 * JSON envelope returned should carry the full health summary under
 * props.sunset.
 *
 * The controller layer's same-route JSON polling path (?refresh=1) does NOT
 * carry the shared prop — that's a known acceptable divergence (see
 * PollingShapeContractTest). The polling client receives `sunset` on the
 * initial mount and re-uses it client-side, so this is correct by design.
 */
class SharedPropsHealthTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Manager::flushAuth();
        Sunset::auth(fn () => true);

        $this->wipeSunsetKeys();
    }

    protected function getPackageProviders($app): array
    {
        // Inertia's ServiceProvider auto-discovery doesn't fire reliably under
        // Testbench when a package's tests render Inertia responses through
        // the full Blade pipeline (vs the controller-level same-route-JSON
        // shortcut). Registering it explicitly here makes the request macro
        // (`$request->inertia()`) available inside Inertia\Middleware.
        return array_merge(parent::getPackageProviders($app), [
            \Inertia\ServiceProvider::class,
        ]);
    }

    private function wipeSunsetKeys(): void
    {
        try {
            $conn = $this->app->make(RedisFactory::class)
                ->connection(config('sunset.redis_connection', 'default'));
            $prefix = method_exists($conn, '_prefix') ? (string) $conn->_prefix('') : '';
            foreach ((array) $conn->keys('sunset:*') as $k) {
                $name = $prefix !== '' ? str_replace($prefix, '', $k) : $k;
                if ($prefix === '' && str_contains((string) $k, 'sunset:')) {
                    $name = substr((string) $k, strpos((string) $k, 'sunset:'));
                }
                $conn->del($name);
            }
        } catch (\Throwable) {
            // best-effort — Redis unreachable, individual assertions will fail.
        }
    }

    /**
     * Build an Inertia XHR request. Setting X-Inertia=true plus the Inertia
     * Accept headers matches what the SPA client sends: Inertia's middleware
     * intercepts and returns a JSON envelope, the dashboard controller's
     * inertiaOrJson() helper does NOT short-circuit (wantsJson() is false
     * because the Accept header isn't application/json).
     */
    private function inertiaRequest(string $path)
    {
        // Inertia's middleware compares X-Inertia-Version against
        // Inertia::getVersion() — which is the empty string by default in a
        // Testbench env (no asset manifest, no closure registered). An empty
        // version header matches, so omit it (or send '').
        return $this->withHeaders([
            'X-Inertia' => 'true',
            'Accept'    => 'text/html, application/xhtml+xml',
        ])->get($path);
    }

    public function test_overview_inertia_render_exposes_sunset_health_payload(): void
    {
        $response = $this->inertiaRequest('/sunset');
        $response->assertStatus(200);

        $sunset = $response->json('props.sunset');
        $this->assertIsArray($sunset, 'Expected props.sunset shared payload');
        $this->assertArrayHasKey('health', $sunset);

        $health = $sunset['health'];
        foreach (['workers', 'pending', 'throughput', 'failed', 'probes', 'workerWarning'] as $key) {
            $this->assertArrayHasKey($key, $health, "Missing health key: {$key}");
        }
        $this->assertIsInt($health['workers']);
        $this->assertIsInt($health['pending']);
        $this->assertIsString($health['throughput']);
        $this->assertIsInt($health['failed']);
        $this->assertIsArray($health['probes']);
    }

    public function test_workload_inertia_render_also_exposes_health_payload(): void
    {
        // The shared prop is keyed off the middleware, not the controller, so
        // every page under the dashboard route group should carry it.
        $response = $this->inertiaRequest('/sunset/workload');
        $response->assertStatus(200);

        $this->assertArrayHasKey('health', $response->json('props.sunset') ?? []);
    }

    public function test_health_route_persists_probes_for_subsequent_renders(): void
    {
        // Hit /sunset/health (refresh=1 path) so the controller records the
        // probe summary to ProbeCache without going through Inertia.
        $this->getJson('/sunset/health?refresh=1')->assertStatus(200);

        // Now any dashboard page should surface those cached probes via the
        // shared health prop.
        $response = $this->inertiaRequest('/sunset');
        $response->assertStatus(200);

        $probes = $response->json('props.sunset.health.probes');
        $this->assertIsArray($probes);
        // The dashboard's own Redis pill is always present whenever the
        // health route has been visited at least once in the current
        // test run.
        $names = array_column($probes, 'name');
        $this->assertContains('redis', $names, 'Expected redis probe in cached results.');
    }
}
