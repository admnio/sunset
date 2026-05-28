<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Contracts\QueuePauseRepository;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Contracts\WorkloadRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
final class WorkloadController extends Controller
{
    public function __construct(private QueuePauseRepository $pauses)
    {
    }

    public function show(
        Request $request,
        WorkloadRepository $workload,
        SupervisorRepository $supervisors,
        MetricsRepository $metrics,
    ): InertiaResponse|JsonResponse {
        return $this->inertiaOrJson($request, 'Sunset/Workload', [
            'queues'        => $workload->get(),
            // v1.3.0: surface the currently-paused (connection, queue) pairs so
            // the page can render a "PAUSED" indicator + flip the per-row
            // pause/resume button. Included in BOTH the initial Inertia render
            // AND the ?refresh=1 polling branch — PollingShapeContractTest
            // guards against the two branches drifting apart.
            'paused_queues'      => $this->pauses->all(),
            // Real worker-slot capacity = sum of every running supervisor's
            // configured max process count. Drives the "active / capacity"
            // utilization stat. 0 when no supervisors are reporting.
            'worker_capacity'    => $this->workerCapacity($supervisors),
            // Real drain rate for the ETA stat. Matches the Overview hero so
            // the two pages agree. 0 on an idle dashboard → page shows "—".
            'throughput_per_min' => $this->totalThroughput($metrics),
        ]);
    }

    /**
     * Sum of configured max processes across all reporting supervisors.
     *
     * @return int
     */
    private function workerCapacity(SupervisorRepository $supervisors): int
    {
        $total = 0;
        foreach ($supervisors->all() as $supervisor) {
            $total += (int) ($supervisor['options']['maxProcesses'] ?? 0);
        }
        return $total;
    }

    /**
     * Sum of the latest per-queue snapshot throughput values — the same
     * derivation OverviewController and HealthSummary use, kept in lockstep.
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
     * POST /sunset/workload/{connection}/{queue}/pause — mark the named queue
     * paused. The QueuePauseRepository fires QueuePaused after the SADD, which
     * the activity-stream recorder picks up. Redirects back so the Inertia
     * client refreshes the page and re-renders with the new paused state.
     *
     * Route parameters arrive URL-decoded by Laravel; pass them through to the
     * repository as-is. The `where` constraint on the route allows any
     * non-slash characters in the queue name (queue names commonly include
     * `-`, `_`, `.`, `:`).
     */
    public function pause(string $connection, string $queue): RedirectResponse
    {
        $this->pauses->pause($connection, $queue, 'dashboard');

        return back();
    }

    /**
     * POST /sunset/workload/{connection}/{queue}/resume — clear the pause on
     * the named queue. Fires QueueResumed. Same redirect-back contract as
     * pause(): the Inertia client reloads the page after the POST so the row
     * picks up the new state.
     */
    public function resume(string $connection, string $queue): RedirectResponse
    {
        $this->pauses->resume($connection, $queue, 'dashboard');

        return back();
    }
}
