<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\RateLimiting\Limit;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\RateLimiting\Targets\JobClassTarget;
use Admnio\Sunset\RateLimiting\Targets\QueueTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

final class RateLimitsController extends Controller
{
    public function show(Request $request, LimitRegistry $registry): InertiaResponse|JsonResponse
    {
        $limits = array_map(
            fn (Limit $limit) => $this->serializeLimit($limit),
            $registry->all()
        );

        return $this->inertiaOrJson($request, 'Sunset/RateLimits', [
            'limits'  => $limits,
            // Reject stats are recorded under sunset:rl:rejects:* keys but
            // the package doesn't yet expose a public API to read them in
            // aggregate. Leave the slot in the payload so the page component
            // can render the column once that API lands.
            'rejects' => [],
        ]);
    }

    private function serializeLimit(Limit $limit): array
    {
        return [
            'name'        => $limit->name,
            'target'      => $this->targetDescription($limit->target),
            'throttle'    => $limit->throttle ? [
                'max'    => $limit->throttle->max,
                'window' => $limit->throttle->windowSeconds,
            ] : null,
            'concurrency' => $limit->concurrency ? [
                'max'      => $limit->concurrency->max,
                'slot_ttl' => $limit->concurrency->slotTtlSeconds,
            ] : null,
            'over_limit'             => $limit->overLimit,
            'fixed_backoff_seconds'  => $limit->fixedBackoffSeconds,
            'drop_as_failure'        => $limit->dropAsFailure,
            'count_releases'         => $limit->countReleases,
        ];
    }

    private function targetDescription(QueueTarget|JobClassTarget $target): string
    {
        if ($target instanceof QueueTarget) {
            return 'queue:' . $target->queueName;
        }

        return 'class:' . $target->jobClass;
    }
}
