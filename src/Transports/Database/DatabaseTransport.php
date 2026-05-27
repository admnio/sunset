<?php

namespace Admnio\Sunset\Transports\Database;

use Admnio\Sunset\Contracts\Transport;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionResolverInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class DatabaseTransport implements Transport
{
    public function __construct(
        private ConnectionResolverInterface $db,
        private array $packageConfig,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function name(): string
    {
        return 'database';
    }

    public function connect(array $config): Queue
    {
        return new DatabaseQueue(
            $this->db->connection($config['connection'] ?? null),
            $config['table'] ?? 'jobs',
            $config['queue'] ?? 'default',
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? null,
        );
    }

    public function workload(array $queues): array
    {
        $logger = $this->logger ?? new NullLogger();
        $dbConfig = $this->packageConfig['transports']['database'] ?? [];
        $table = $dbConfig['table'] ?? 'jobs';

        try {
            $conn = $this->db->connection($dbConfig['workload_connection'] ?? null);
        } catch (Throwable $e) {
            $logger->warning('sunset: database workload connection failed', [
                'error' => $e->getMessage(),
            ]);

            return array_map(fn ($queue) => $this->emptyRecord($queue), $queues);
        }

        $records = [];
        foreach ($queues as $queue) {
            try {
                $length = (int) $conn->table($table)->where('queue', $queue)->count();
            } catch (Throwable $e) {
                $logger->warning('sunset: database workload query failed for queue', [
                    'queue' => $queue,
                    'error' => $e->getMessage(),
                ]);
                $length = 0;
            }

            $records[] = [
                'name' => $queue,
                'length' => $length,
                'wait' => 0,
                'processes' => 0,
                'split_queues' => null,
            ];
        }

        return $records;
    }

    private function emptyRecord(string $queue): array
    {
        return [
            'name' => $queue,
            'length' => 0,
            'wait' => 0,
            'processes' => 0,
            'split_queues' => null,
        ];
    }
}
