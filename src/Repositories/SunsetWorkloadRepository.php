<?php

namespace Admnio\Sunset\Repositories;

use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Contracts\WorkloadRepository;
use Admnio\Sunset\Support\TransportRegistry;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class SunsetWorkloadRepository implements WorkloadRepository
{
    public function __construct(
        private TransportRegistry $transports,
        private MetricsRepository $metrics,
        private SupervisorRepository $supervisors,
        private Cache $cache,
        private array $queues,
        private int $cacheTtlSeconds,
        /**
         * The transport names to query for workload depth. Restricting this to
         * the transports actually in use (derived from the configured
         * supervisors' connections) avoids blocking the dashboard on backends
         * that aren't running — e.g. querying SQS/RabbitMQ on a deployment that
         * only uses the database queue would otherwise stall on connect
         * timeouts. Empty means "all registered transports" (legacy behavior).
         *
         * @var list<string>
         */
        private array $transportNames = [],
    ) {
    }

    public function get(): array
    {
        return $this->cache->remember(
            'sunset:workload',
            $this->cacheTtlSeconds,
            fn () => $this->fetch()
        );
    }

    private function fetch(): array
    {
        $rawWorkload = $this->mergeWorkloadAcrossTransports();
        $perQueueProcesses = $this->processesPerQueue();

        $records = [];
        foreach ($rawWorkload as $entry) {
            $queue = $entry['name'];
            $length = (int) $entry['length'];
            $procs = max(1, (int) $this->lookupProcessCount($queue, $perQueueProcesses));
            $runtime = (float) $this->metrics->runtimeForQueue($queue);

            $records[] = [
                'name' => $queue,
                // v1.3.0: source transport name (e.g. 'sqs', 'redis', 'rabbitmq')
                // — surfaced so the dashboard's per-row pause/resume button knows
                // which (connection, queue) pair to write through the
                // QueuePauseRepository. Additive, backward-compatible field.
                'connection' => $entry['connection'] ?? null,
                'length' => $length,
                'wait' => (int) round($length * $runtime / $procs),
                'processes' => $procs,
                'split_queues' => $entry['split_queues'] ?? null,
            ];
        }

        return $records;
    }

    /**
     * Merge workload records across all registered transports.
     *
     * Each transport returns a record per queue we asked about. For queues that
     * live in exactly one transport, the others report length=0 — so summing
     * across transports is safe and correctly reports the actual depth. For a
     * queue that somehow has jobs in multiple transports, summing is also the
     * desired behavior (total pending work).
     *
     * @return array<int, array{name: string, length: int, wait: int, processes: int, split_queues: mixed}>
     */
    private function mergeWorkloadAcrossTransports(): array
    {
        $merged = [];

        // Only query the transports actually in use. Falls back to every
        // registered transport when no scope was provided (legacy behavior),
        // and always intersects with what's registered so a stale config name
        // can't trigger a lookup for an unregistered transport.
        $names = $this->transportNames
            ? array_values(array_intersect($this->transportNames, $this->transports->names()))
            : $this->transports->names();

        foreach ($names as $name) {
            foreach ($this->transports->get($name)->workload($this->queues) as $record) {
                $queue = $record['name'];
                // v1.3.0: tag the record with the transport name so the
                // dashboard can route pause/resume POSTs to the right
                // (connection, queue) pair. Transports don't populate this
                // themselves (kept the per-transport workload() shape stable);
                // we stamp it during the merge instead.
                $record['connection'] = $record['connection'] ?? $name;

                if (! isset($merged[$queue])) {
                    $merged[$queue] = $record;
                    continue;
                }
                $merged[$queue]['length'] += (int) $record['length'];
                $merged[$queue]['split_queues'] = $merged[$queue]['split_queues'] ?? ($record['split_queues'] ?? null);
                // When the same queue name lives in multiple transports, the
                // FIRST connection we saw wins — matches the existing "first
                // record wins" semantics for the rest of the row fields.
            }
        }
        return array_values($merged);
    }

    /**
     * Aggregate per-queue process counts across all supervisors.
     *
     * Mirrors Horizon's RedisWorkloadRepository::processes() shape so existing
     * UI/clients keep working unchanged.
     * Each supervisor record exposes a `processes` map keyed by `connection:queue`
     * (e.g. `sqs:orders`); counts are summed across supervisors per key.
     *
     * @return array<string, int>
     */
    private function processesPerQueue(): array
    {
        return collect($this->supervisors->all())
            ->pluck('processes')
            ->reduce(function ($final, $queues) {
                foreach ((array) $queues as $queue => $processes) {
                    $final[$queue] = isset($final[$queue])
                        ? $final[$queue] + $processes
                        : $processes;
                }

                return $final;
            }, []) ?? [];
    }

    /**
     * Find the process count for a queue, tolerant of `connection:queue` keys.
     * When multiple connections route the same queue name, counts are summed.
     */
    private function lookupProcessCount(string $queue, array $perQueue): int
    {
        if (array_key_exists($queue, $perQueue)) {
            return (int) $perQueue[$queue];
        }

        $total = 0;
        foreach ($perQueue as $key => $count) {
            if (! is_string($key)) {
                continue;
            }
            $colon = strpos($key, ':');
            if ($colon !== false && substr($key, $colon + 1) === $queue) {
                $total += (int) $count;
            }
        }

        return $total;
    }
}
