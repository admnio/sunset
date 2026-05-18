<?php

namespace Admnio\Sunset\Repositories;

use Admnio\Sunset\Support\TransportRegistry;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

class SunsetWorkloadRepository implements WorkloadRepository
{
    public function __construct(
        private TransportRegistry $transports,
        private string $transportName,
        private MetricsRepository $metrics,
        private SupervisorRepository $supervisors,
        private Cache $cache,
        private array $queues,
        private int $cacheTtlSeconds,
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
        $rawWorkload = $this->transports->get($this->transportName)->workload($this->queues);
        $perQueueProcesses = $this->processesPerQueue();

        $records = [];
        foreach ($rawWorkload as $entry) {
            $queue = $entry['name'];
            $length = (int) $entry['length'];
            $procs = max(1, (int) $this->lookupProcessCount($queue, $perQueueProcesses));
            $runtime = (float) $this->metrics->runtimeForQueue($queue);

            $records[] = [
                'name' => $queue,
                'length' => $length,
                'wait' => (int) round($length * $runtime / $procs),
                'processes' => $procs,
                'split_queues' => $entry['split_queues'] ?? null,
            ];
        }

        return $records;
    }

    /**
     * Aggregate per-queue process counts across all supervisors.
     *
     * Mirrors {@see \Laravel\Horizon\Repositories\RedisWorkloadRepository::processes()}.
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
