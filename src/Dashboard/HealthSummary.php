<?php

namespace Admnio\Sunset\Dashboard;

use Admnio\Sunset\Contracts\FailedJobRepository;
use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Contracts\WorkerMetricsRepository;
use Admnio\Sunset\Contracts\WorkloadRepository;
use Throwable;

/**
 * Aggregates the at-a-glance numbers rendered by the dashboard's HealthStrip:
 * worker count, pending queue depth, recent throughput, recent failures, the
 * most recently cached transport probes, and the highest-RSS worker that has
 * crossed the 100MB heuristic threshold.
 *
 * Designed to be cheap on the request hot path:
 *  - Each {@see compute()} call is memoised per-instance via {@see $cached},
 *    so multiple shared-prop reads in a single Inertia response do not re-hit
 *    Redis.
 *  - {@see compute()} catches Throwables internally and degrades to empty
 *    arrays / zeros when the backing repositories are unreachable. The strip
 *    renders something sane even when Redis is down.
 *
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v2.x. Consumers should not
 *           depend on it directly.
 */
final class HealthSummary
{
    /** Workers exceeding this RSS get surfaced as the strip's warning pill. */
    private const WORKER_RSS_WARNING_BYTES = 100 * 1024 * 1024;

    /**
     * Per-request memoisation. The middleware constructs a fresh instance per
     * request, so an instance property is sufficient — no static needed.
     *
     * @var array<string, mixed>|null
     */
    private ?array $cached = null;

    public function __construct(
        private readonly WorkloadRepository $workload,
        private readonly SupervisorRepository $supervisors,
        private readonly FailedJobRepository $failures,
        private readonly WorkerMetricsRepository $workers,
        private readonly MetricsRepository $metrics,
        private readonly ProbeCache $probes,
    ) {
    }

    /**
     * Build the HealthStrip payload for the current request.
     *
     * @return array{
     *   workers: int,
     *   pending: int,
     *   throughput: string,
     *   failed: int,
     *   probes: array<int, array<string, mixed>>,
     *   workerWarning: array{name: string, detail: string}|null
     * }
     */
    public function compute(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        return $this->cached = [
            'workers'       => $this->safeInt(fn () => $this->totalWorkers()),
            'pending'       => $this->safeInt(fn () => $this->totalPending()),
            'throughput'    => $this->safeString(fn () => $this->formatThroughput($this->totalThroughput()), '0'),
            'failed'        => $this->safeInt(fn () => $this->failures->countRecentlyFailed()),
            'probes'        => $this->safeArray(fn () => $this->probes->recent()),
            'workerWarning' => $this->safeWarning(fn () => $this->workerWarning()),
        ];
    }

    /**
     * Total worker processes across every supervisor's connection:queue map.
     */
    private function totalWorkers(): int
    {
        $total = 0;
        foreach ($this->supervisors->all() as $sup) {
            foreach ((array) ($sup['processes'] ?? []) as $count) {
                $total += (int) $count;
            }
        }
        return $total;
    }

    /**
     * Sum of pending job counts across every queue surfaced by the workload
     * repository. Cross-transport: the workload repo already merges SQS /
     * Redis / RabbitMQ depths.
     */
    private function totalPending(): int
    {
        $total = 0;
        foreach ($this->workload->get() as $row) {
            $total += (int) ($row['length'] ?? 0);
        }
        return $total;
    }

    /**
     * Sum of the latest per-queue snapshot throughput values across every
     * tracked queue. Snapshots are written by {@see MetricsRepository::snapshot()}
     * on a recurring schedule; the LATEST entry of each queue's series is the
     * jobs-per-snapshot-interval count.
     */
    private function totalThroughput(): int
    {
        $total = 0;
        foreach ($this->metrics->queues() as $queue) {
            $snapshots = $this->metrics->snapshotsForQueue((string) $queue);
            if ($snapshots === []) {
                continue;
            }
            $latest = end($snapshots);
            $total += (int) ($latest['throughput'] ?? 0);
        }
        return $total;
    }

    /**
     * Format a raw throughput count for the strip. Values under 1000 render
     * verbatim; >=1000 collapse to a k-suffixed short form (e.g. 1.2k, 12k).
     *
     * Kept here (vs a free function) so the controller layer can reuse it via
     * the public {@see formatCount()} helper without exposing internals.
     */
    private function formatThroughput(int $value): string
    {
        return self::formatCount($value);
    }

    /**
     * Locate the worker process with the highest RSS that has crossed the
     * 100MB heuristic. Returns {name, detail} or null when no worker tripped
     * the threshold. "name" uses the PID as a stable identifier; "detail"
     * formats the RSS as a human-readable MB count.
     *
     * @return array{name: string, detail: string}|null
     */
    private function workerWarning(): ?array
    {
        $snapshots = $this->workers->all();
        if ($snapshots === []) {
            return null;
        }

        $worst = null;
        foreach ($snapshots as $snap) {
            if ($snap->rssBytes < self::WORKER_RSS_WARNING_BYTES) {
                continue;
            }
            if ($worst === null || $snap->rssBytes > $worst->rssBytes) {
                $worst = $snap;
            }
        }

        if ($worst === null) {
            return null;
        }

        $mb = (int) round($worst->rssBytes / (1024 * 1024));

        return [
            'name'   => "worker {$worst->pid}",
            'detail' => "{$mb}MB",
        ];
    }

    /**
     * Public utility shared with controllers that need the same compact
     * "1.2k" / "421" rendering — keeps the formatter in one place.
     */
    public static function formatCount(int $value): string
    {
        if ($value < 1000) {
            return (string) $value;
        }
        if ($value < 10000) {
            // One decimal for sub-10k, e.g. 1.2k. Trim trailing .0 for cleanliness.
            $short = number_format($value / 1000, 1, '.', '');
            if (str_ends_with($short, '.0')) {
                $short = substr($short, 0, -2);
            }
            return $short . 'k';
        }
        return ((int) round($value / 1000)) . 'k';
    }

    private function safeInt(callable $fn): int
    {
        try {
            return (int) $fn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function safeString(callable $fn, string $fallback): string
    {
        try {
            return (string) $fn();
        } catch (Throwable) {
            return $fallback;
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function safeArray(callable $fn): array
    {
        try {
            $value = $fn();
            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{name: string, detail: string}|null
     */
    private function safeWarning(callable $fn): ?array
    {
        try {
            $value = $fn();
            return is_array($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
