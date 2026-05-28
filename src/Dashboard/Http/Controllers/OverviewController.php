<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\ActivityRepository;
use Admnio\Sunset\Contracts\FailedJobRepository;
use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Contracts\WorkloadRepository;
use Admnio\Sunset\Dashboard\HealthSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
final class OverviewController extends Controller
{
    public function show(
        Request $request,
        WorkloadRepository $workload,
        SupervisorRepository $supervisors,
        MasterSupervisorRepository $masters,
        ActivityRepository $activity,
        MetricsRepository $metrics,
        FailedJobRepository $failures,
    ): InertiaResponse|JsonResponse {
        $totalThroughput   = $this->totalThroughput($metrics);
        $failuresLastHour  = $failures->countRecentlyFailed();
        // Failure rate is failures / (completions + failures). Use the robust
        // recent-completion count (live counter + snapshot history) rather than
        // the latest-snapshot rate, so it stays accurate even before the
        // snapshot scheduler has run.
        $failureRatePct    = $this->failureRatePct($this->recentCompletions($metrics), $failuresLastHour);

        $props = [
            'workload'            => $workload->get(),
            'supervisors'         => $supervisors->all(),
            'masters'             => $masters->all(),
            // Recent activity-stream events (same shape as the Activity page),
            // previewed in the Overview's "Recent activity" panel.
            'recent'              => array_map(
                static fn ($e) => $e->toArray(),
                $activity->recent(10),
            ),
            'throughput_per_min'  => HealthSummary::formatCount($totalThroughput),
            'failure_rate_pct'    => $failureRatePct,
            'failures_last_hour'  => $failuresLastHour,
            // Real recent throughput trend for the Throughput stat-card
            // sparkline. Empty until snapshots exist.
            'throughput_series'   => $this->aggregateMetricSeries($metrics, 'throughput'),
        ];

        return $this->inertiaOrJson($request, 'Sunset/Overview', $props);
    }

    /**
     * Sum of the latest per-queue snapshot throughput values. Matches the
     * derivation used by HealthSummary so the strip's "/min" figure and the
     * Overview hero stat stay in lockstep.
     */
    private function totalThroughput(MetricsRepository $metrics): int
    {
        $total = 0;
        foreach ($metrics->queues() as $queue) {
            $snapshots = $metrics->snapshotsForQueue((string) $queue);
            if ($snapshots === []) {
                continue;
            }
            $latest = end($snapshots);
            $total += (int) ($latest['throughput'] ?? 0);
        }
        return $total;
    }

    /**
     * Failure rate as a string percentage with 2 decimals, or "—" when there
     * isn't enough throughput to compute a meaningful ratio (avoids divide-
     * by-zero and the misleading 0.00% reading on idle dashboards).
     */
    private function failureRatePct(int $completions, int $failures): string
    {
        $total = $completions + $failures;
        if ($total <= 0) {
            return '—';
        }
        return number_format(($failures / $total) * 100, 2, '.', '');
    }
}
