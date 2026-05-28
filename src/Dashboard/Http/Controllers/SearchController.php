<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Contracts\WorkloadRepository;
use Illuminate\Http\JsonResponse;

/**
 * Backs the command palette's "Queues" and "Job classes" groups with real
 * data. Lazy-loaded once on first palette open (not on every page render), so
 * it stays off the hot path of normal navigation.
 *
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
final class SearchController extends Controller
{
    public function index(WorkloadRepository $workload, MetricsRepository $metrics): JsonResponse
    {
        $queues = [];
        foreach ($workload->get() as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $connection = $row['connection'] ?? null;
            $length = (int) ($row['length'] ?? 0);
            $meta = $connection ? "{$connection} · {$length} pending" : "{$length} pending";
            $queues[] = ['label' => $name, 'meta' => $meta];
        }

        $classes = [];
        foreach ($metrics->jobs() as $job) {
            $job = (string) $job;
            if ($job !== '') {
                $classes[] = ['label' => $job];
            }
        }

        return response()->json([
            'queues'  => $queues,
            'classes' => $classes,
        ]);
    }
}
