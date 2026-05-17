<?php

namespace MasonWorkforce\HorizonSqs\Repositories;

use Aws\Sqs\SqsClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\ProcessRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

class SqsWorkloadRepository implements WorkloadRepository
{
    public function __construct(
        private SqsClient $sqs,
        private MetricsRepository $metrics,
        private ProcessRepository $processes,
        private Cache $cache,
        private string $queuePrefix,
        private array $queues,
        private int $cacheTtlSeconds,
    ) {
    }

    public function get(): array
    {
        return $this->cache->remember(
            'horizon-sqs:workload',
            $this->cacheTtlSeconds,
            fn () => $this->fetch()
        );
    }

    private function fetch(): array
    {
        $perQueueProcesses = $this->processes->processesPerQueue();

        $promises = [];
        foreach ($this->queues as $queue) {
            $promises[$queue] = $this->sqs->getQueueAttributesAsync([
                'QueueUrl' => $this->queuePrefix . '/' . $queue,
                'AttributeNames' => ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible'],
            ]);
        }

        $workload = [];
        foreach ($promises as $queue => $promise) {
            $result = $promise->wait();
            $attrs = $result['Attributes'] ?? [];
            $length = (int) ($attrs['ApproximateNumberOfMessages'] ?? 0);
            $runtime = (float) $this->metrics->runtimeForQueue($queue);
            $procs = max(1, (int) ($perQueueProcesses[$queue] ?? 0));

            $workload[] = [
                'name' => $queue,
                'length' => $length,
                'wait' => (int) round($length * $runtime / $procs),
                'processes' => $procs,
                'split' => null,
            ];
        }

        return $workload;
    }
}
