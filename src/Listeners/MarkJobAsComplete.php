<?php

namespace Admnio\Sunset\Listeners;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Events\JobCompleted;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class MarkJobAsComplete
{
    public function __construct(
        private JobRepository $jobs,
        private MetricsRepository $metrics,
    ) {}

    public function handle(JobCompleted $event): void
    {
        $silenced = (bool) ($event->payload->decoded['silenced'] ?? false);

        $name = $event->payload->decoded['displayName'] ?? '';
        $pushedAt = (float) ($event->payload->decoded['pushedAt'] ?? microtime(true));
        $runtime = max(0.0, microtime(true) - $pushedAt);

        // Persist the per-job runtime (ms) on the job record so the Recent /
        // Completed pages can show it — the same figure fed to metrics below.
        $this->jobs->completed($event->payload, $silenced, $runtime * 1000);

        $this->metrics->incrementThroughput($name, $event->queue, $runtime);
    }
}
