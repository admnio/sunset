<?php

namespace Admnio\Sunset\Dashboard\Http\Middleware;

use Admnio\Sunset\Dashboard\HealthSummary;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Pin the Inertia root view to Sunset's bundled Blade template for the
 * duration of the dashboard request, without mutating Inertia's global
 * config (which would affect the consumer's whole app). Inertia caches the
 * root view per-instance; since the middleware container is rebuilt on every
 * request, calling setRootView() here is request-scoped.
 *
 * Also shares the `sunset.health` payload consumed by the dashboard's
 * HealthStrip. The HealthSummary call is wrapped in try/catch so a transient
 * Redis outage degrades the strip to a sane empty state rather than 500ing
 * the whole dashboard.
 *
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class SetSunsetInertiaRoot
{
    public function __construct(private readonly HealthSummary $summary)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        Inertia::setRootView('sunset::sunset-app');

        Inertia::share('sunset', [
            'pollIntervalSeconds' => (int) config('sunset.dashboard.poll_interval_seconds', 3),
            'path'                => config('sunset.dashboard.path', 'sunset'),
            'health'              => $this->safeHealth(),
        ]);

        return $next($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function safeHealth(): array
    {
        try {
            return $this->summary->compute();
        } catch (Throwable) {
            // Health is a non-load-bearing UI hint — never let it 500 the
            // dashboard. The HealthStrip handles empty arrays gracefully.
            return [
                'workers'       => 0,
                'pending'       => 0,
                'throughput'    => '0',
                'failed'        => 0,
                'probes'        => [],
                'workerWarning' => null,
            ];
        }
    }
}
