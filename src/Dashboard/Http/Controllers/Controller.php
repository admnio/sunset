<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\MetricsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as LaravelController;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
abstract class Controller extends LaravelController
{
    /**
     * Render the page via Inertia for a full navigation, or return the same
     * props as JSON when the SPA polls the route with ?refresh=1 (or anything
     * else that signals an XHR). This is the foundation of the "same-route
     * polling" pattern used throughout the dashboard.
     */
    protected function inertiaOrJson(Request $request, string $page, array $props): InertiaResponse|JsonResponse
    {
        if ($request->query('refresh') === '1' || $request->wantsJson()) {
            return response()->json(['props' => $props]);
        }

        return Inertia::render($page, $props);
    }

    /**
     * Build a normalized (0..1) recent trend series for a dashboard stat card,
     * aggregated across every recorded queue's snapshots and aligned by the
     * snapshot timestamp. `$field` is 'throughput' (summed across queues) or
     * 'runtime' (averaged across queues). Returns an empty array when nothing
     * has been recorded yet — the <Sparkline> renders nothing in that case,
     * which is honest: no fabricated trend on an idle dashboard.
     *
     * @return list<float>
     */
    protected function aggregateMetricSeries(MetricsRepository $metrics, string $field, int $maxPoints = 30): array
    {
        $sumByTime = [];
        $countByTime = [];

        foreach ($metrics->queues() as $queue) {
            foreach ($metrics->snapshotsForQueue((string) $queue) as $snapshot) {
                $time = (int) ($snapshot['time'] ?? 0);
                if ($time === 0) {
                    continue;
                }
                $sumByTime[$time] = ($sumByTime[$time] ?? 0.0) + (float) ($snapshot[$field] ?? 0);
                $countByTime[$time] = ($countByTime[$time] ?? 0) + 1;
            }
        }

        if ($sumByTime === []) {
            return [];
        }

        ksort($sumByTime);

        $values = [];
        foreach ($sumByTime as $time => $sum) {
            // Throughput is a total across queues; runtime is a mean.
            $values[] = $field === 'runtime'
                ? $sum / max(1, $countByTime[$time])
                : $sum;
        }

        $values = array_slice($values, -$maxPoints);

        $max = max($values);
        if ($max <= 0) {
            return array_map(static fn () => 0.0, $values);
        }

        return array_map(static fn ($v) => round($v / $max, 4), $values);
    }

    /**
     * Recent successful-completion count across all queues, robust to whether
     * the `sunset:snapshot` scheduler is running. It sums each queue's live
     * throughput counter (completions since the last snapshot) plus its
     * retained snapshot history. Without this, a dashboard whose snapshots
     * haven't run yet reports 0 completions — which makes the failure rate
     * collapse to 100% the moment a single job fails.
     */
    protected function recentCompletions(MetricsRepository $metrics): int
    {
        $total = 0;
        foreach ($metrics->queues() as $queue) {
            $queue = (string) $queue;
            $total += $metrics->throughputForQueue($queue);
            foreach ($metrics->snapshotsForQueue($queue) as $snapshot) {
                $total += (int) ($snapshot['throughput'] ?? 0);
            }
        }
        return $total;
    }
}
