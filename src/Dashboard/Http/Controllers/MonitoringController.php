<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\TagRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
final class MonitoringController extends Controller
{
    public function show(Request $request, TagRepository $tags): InertiaResponse|JsonResponse
    {
        $pinned = $tags->monitored();

        // Per-tag job count, useful for the index page.
        $counts = [];
        foreach ($pinned as $tag) {
            $counts[$tag] = $tags->count($tag);
        }

        return $this->inertiaOrJson($request, 'Sunset/Monitoring', [
            'pinned' => $pinned,
            'counts' => $counts,
        ]);
    }

    /**
     * v2.0 — detail page for a single monitored tag. Surfaces:
     *   - Total count + pinned status
     *   - Tagged jobs (most recent first)
     *   - Per-class breakdown (which job classes carried this tag)
     */
    public function tag(string $tag, Request $request, TagRepository $tags): InertiaResponse|JsonResponse
    {
        $count = $tags->count($tag);
        $pinned = in_array($tag, $tags->monitored(), true);

        // Pull the first page of tagged jobs. Each entry typically carries a
        // `display_name` or `name` we can group by for the class breakdown.
        $jobs = $tags->jobs($tag, null)->all();

        // Group by job class to build the breakdown table.
        $byClass = [];
        foreach ($jobs as $job) {
            $cls = $job->name ?? $job->display_name ?? $job->type ?? 'unknown';
            $byClass[$cls] ??= [
                'class' => $cls,
                'queue' => $job->queue ?? '—',
                'count' => 0,
                'failed' => 0,
                'last_seen' => null,
            ];
            $byClass[$cls]['count']++;
            if (($job->status ?? null) === 'failed') {
                $byClass[$cls]['failed']++;
            }
            if (! empty($job->pushed_at) || ! empty($job->completed_at)) {
                $ts = $job->completed_at ?? $job->pushed_at;
                if ($byClass[$cls]['last_seen'] === null || $ts > $byClass[$cls]['last_seen']) {
                    $byClass[$cls]['last_seen'] = $ts;
                }
            }
        }
        $classes = array_values($byClass);
        usort($classes, static fn ($a, $b) => $b['count'] <=> $a['count']);

        // Recent runs — first 20 from the jobs() collection.
        $recentRuns = array_slice($jobs, 0, 20);

        $stats = [
            'total_seen' => $count,
            'in_last_hour' => 0,            // TODO(v2-wire-data): no time-bucketed count for tags yet
            'last_seen_at' => $jobs[0]->pushed_at ?? $jobs[0]->completed_at ?? null,
            'classes_count' => count($classes),
            'failed' => array_sum(array_column($classes, 'failed')),
            'pinned' => $pinned,
        ];

        return $this->inertiaOrJson($request, 'Sunset/TagDetail', [
            'tag' => $tag,
            'stats' => $stats,
            'classes' => $classes,
            'recent_runs' => $recentRuns,
            'activity_series' => [],      // TODO(v2-wire-data): no time-series for tag activity yet
        ]);
    }

    public function jobsForTag(string $tag, Request $request, TagRepository $tags): JsonResponse
    {
        $afterIndex = $request->query('after');
        $afterIndex = $afterIndex !== null ? (string) $afterIndex : null;

        return response()->json([
            'tag'   => $tag,
            'count' => $tags->count($tag),
            'jobs'  => $tags->jobs($tag, $afterIndex)->all(),
        ]);
    }

    public function pin(string $tag, TagRepository $tags): JsonResponse
    {
        $tags->monitor($tag);

        return response()->json(['pinned' => true]);
    }

    public function unpin(string $tag, TagRepository $tags): JsonResponse
    {
        $tags->stopMonitoring($tag);

        return response()->json(['unpinned' => true]);
    }
}
