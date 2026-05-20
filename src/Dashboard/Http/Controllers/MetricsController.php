<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\MetricsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

final class MetricsController extends Controller
{
    public function show(Request $request, MetricsRepository $metrics): InertiaResponse|JsonResponse
    {
        $jobs   = $metrics->jobs();
        $queues = $metrics->queues();

        return $this->inertiaOrJson($request, 'Sunset/Metrics', [
            'jobs'             => $jobs,
            'queues'           => $queues,
            'snapshot_taken_at'=> $metrics->latestSnapshotAt(),
            'wait_times'       => $metrics->acquireWaitTimes(),
        ]);
    }

    public function jobSeries(string $job, MetricsRepository $metrics): JsonResponse
    {
        return response()->json([
            'job'        => $job,
            'snapshots'  => $metrics->snapshotsForJob($job),
            'throughput' => $metrics->throughputForJob($job),
            'runtime'    => $metrics->runtimeForJob($job),
        ]);
    }

    public function queueSeries(string $queue, MetricsRepository $metrics): JsonResponse
    {
        return response()->json([
            'queue'      => $queue,
            'snapshots'  => $metrics->snapshotsForQueue($queue),
            'throughput' => $metrics->throughputForQueue($queue),
            'runtime'    => $metrics->runtimeForQueue($queue),
        ]);
    }
}
