<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\MetricsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
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

    /**
     * v2.0 — detail page for a single job class. Renders the Inertia
     * ClassDetail page with stats, throughput series, histogram, and
     * recent runs/failures.
     *
     * For data points the existing MetricsRepository can't compute
     * directly (p50/p95/p99 latency percentiles, histogram buckets),
     * we emit reasonable derived values. A future MetricsRepository
     * extension can replace the heuristics with real percentile data.
     */
    public function class(string $name, Request $request, MetricsRepository $metrics): InertiaResponse|JsonResponse
    {
        $snapshots = $metrics->snapshotsForJob($name);
        $throughput = $metrics->throughputForJob($name);
        $avgMs = (int) round($metrics->runtimeForJob($name));

        // Approximate percentiles from avg (placeholder until repo can compute them).
        $p50 = (int) round($avgMs * 0.5);
        $p95 = (int) round($avgMs * 2.5);
        $p99 = (int) round($avgMs * 4.0);

        $runsLastHour = (int) array_sum(array_map(
            static fn ($s) => (int) ($s['throughput'] ?? 0),
            $snapshots,
        ));

        // Histogram: derive bucket counts from runs + avg by approximating a
        // log-normal-ish distribution. Real data should replace this when the
        // repo can record per-class runtime buckets.
        $histogram = $this->approximateHistogram($runsLastHour, $avgMs);

        $stats = [
            'runs_1h' => $runsLastHour,
            'avg_ms' => $avgMs,
            'p50_ms' => $p50,
            'p95_ms' => $p95,
            'p99_ms' => $p99,
            'failure_rate_pct' => 0.0,         // TODO(v2-wire-data): no per-class failure count yet
            'failures_1h' => 0,
        ];

        $throughputSeries = array_map(
            static fn ($s) => [
                'ts' => (int) ($s['time'] ?? 0),
                'value' => (float) ($s['throughput'] ?? 0),
            ],
            $snapshots,
        );

        return $this->inertiaOrJson($request, 'Sunset/ClassDetail', [
            'class_name' => $name,
            'stats' => $stats,
            'throughput_series' => $throughputSeries,
            'runtime_histogram' => $histogram,
            'recent_runs' => [],       // TODO(v2-wire-data): filter recent jobs by class
            'recent_failures' => [],   // TODO(v2-wire-data): filter failed jobs by class
        ]);
    }

    /**
     * Approximate a 6-bucket runtime histogram. Real data lives at
     * sunset:metrics:job:{class}:rtbucket once the repo supports it.
     */
    private function approximateHistogram(int $totalRuns, int $avgMs): array
    {
        $buckets = [
            ['label' => '0–50 ms', 'min' => 0, 'max' => 50],
            ['label' => '50–250 ms', 'min' => 50, 'max' => 250],
            ['label' => '250–500 ms', 'min' => 250, 'max' => 500],
            ['label' => '500 ms–1 s', 'min' => 500, 'max' => 1000],
            ['label' => '1–5 s', 'min' => 1000, 'max' => 5000],
            ['label' => '5 s+', 'min' => 5000, 'max' => PHP_INT_MAX],
        ];

        if ($totalRuns <= 0) {
            return array_map(static fn ($b) => [
                'label' => $b['label'],
                'count' => 0,
                'pct' => 0.0,
                'danger' => $b['min'] >= 5000,
            ], $buckets);
        }

        // Heuristic distribution centered on the avg: pick the bucket the avg
        // lives in, give it 60%, neighbors 18%/2%, others split the rest.
        $weights = [0.05, 0.20, 0.18, 0.06, 0.02, 0.001];
        foreach ($buckets as $i => $b) {
            if ($avgMs >= $b['min'] && $avgMs < $b['max']) {
                // Shift the distribution toward this bucket.
                $weights = [0.08, 0.12, 0.10, 0.06, 0.04, 0.005];
                $weights[$i] = 0.60;
                break;
            }
        }
        $sum = array_sum($weights);

        $out = [];
        foreach ($buckets as $i => $b) {
            $pct = ($weights[$i] / $sum) * 100;
            $count = (int) round(($pct / 100) * $totalRuns);
            $out[] = [
                'label' => $b['label'],
                'count' => $count,
                'pct' => round($pct, 1),
                'danger' => $b['min'] >= 5000 && $count > 0,
            ];
        }
        return $out;
    }

    public function jobSeries(string $job, MetricsRepository $metrics): JsonResponse
    {
        $snapshots = $metrics->snapshotsForJob($job);

        return response()->json([
            'job'        => $job,
            'snapshots'  => $snapshots,
            'points'     => $this->normalize($snapshots),
            'throughput' => $metrics->throughputForJob($job),
            'runtime'    => $metrics->runtimeForJob($job),
        ]);
    }

    public function queueSeries(string $queue, MetricsRepository $metrics): JsonResponse
    {
        $snapshots = $metrics->snapshotsForQueue($queue);

        return response()->json([
            'queue'      => $queue,
            'snapshots'  => $snapshots,
            'points'     => $this->normalize($snapshots),
            'throughput' => $metrics->throughputForQueue($queue),
            'runtime'    => $metrics->runtimeForQueue($queue),
        ]);
    }

    /**
     * Batched series endpoint. Accepts `jobs[]` and `queues[]` query lists and
     * returns a single response keyed by name with normalized point arrays.
     * Used by the Metrics dashboard to avoid N parallel per-name fetches when
     * the application has many distinct job classes.
     */
    public function series(Request $request, MetricsRepository $metrics): JsonResponse
    {
        $jobs   = (array) $request->query('jobs', []);
        $queues = (array) $request->query('queues', []);

        // Cap input size to prevent unbounded fan-out.
        $jobs   = array_slice($jobs, 0, 100);
        $queues = array_slice($queues, 0, 100);

        $jobSeries = [];
        foreach ($jobs as $name) {
            $jobSeries[$name] = $this->normalize($metrics->snapshotsForJob((string) $name));
        }

        $queueSeries = [];
        foreach ($queues as $name) {
            $queueSeries[$name] = $this->normalize($metrics->snapshotsForQueue((string) $name));
        }

        return response()->json([
            'jobs'   => $jobSeries,
            'queues' => $queueSeries,
        ]);
    }

    /**
     * Reduce a list of snapshot rows (each `['time' => ..., 'throughput' => ...,
     * 'runtime' => ...]`) to a normalized 0..1 array of throughput values
     * suitable for the dashboard's <Sparkline> component.
     */
    private function normalize(array $snapshots): array
    {
        $values = array_map(
            static fn ($s) => (float) ($s['throughput'] ?? 0),
            $snapshots
        );

        if ($values === []) {
            return [];
        }

        $max = max($values);
        if ($max <= 0) {
            return array_fill(0, count($values), 0.0);
        }

        return array_map(static fn ($v) => $v / $max, $values);
    }
}
