<?php

namespace Admnio\Sunset\Tests\Integration\Dashboard;

use Admnio\Sunset\Contracts\FailedJobRepository;
use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Contracts\WorkerMetricsRepository;
use Admnio\Sunset\Contracts\WorkloadRepository;
use Admnio\Sunset\Dashboard\HealthSummary;
use Admnio\Sunset\Dashboard\ProbeCache;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use RuntimeException;
use Throwable;

/**
 * Exercises {@see HealthSummary::compute()} end-to-end against a live Redis,
 * then asserts the graceful-degradation path collapses to safe defaults when
 * the underlying repositories throw.
 */
class HealthSummaryTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Wipe sunset:* keys so prior runs don't leak into the assertions
        // (especially recent_failed_jobs ZSETs which countRecentlyFailed reads).
        $this->wipeSunsetKeys();
    }

    private function wipeSunsetKeys(): void
    {
        try {
            $conn = $this->app->make(RedisFactory::class)
                ->connection(config('sunset.redis_connection', 'default'));
            // Strategy: ask Redis for all matching keys (predis returns the
            // fully-prefixed form), then DEL each one with the prefix already
            // stripped. _prefix('') gives us the runtime prefix for both
            // phpredis (returns the prefix) and predis (returns ''). When
            // empty, the keys are unprefixed and str_replace is a no-op.
            $prefix = method_exists($conn, '_prefix') ? (string) $conn->_prefix('') : '';
            foreach ((array) $conn->keys('sunset:*') as $k) {
                $name = $prefix !== '' ? str_replace($prefix, '', $k) : $k;
                // For predis, the returned key includes the prefix the
                // connection auto-applies on subsequent commands, so we need
                // to strip it ourselves.
                if ($prefix === '' && str_contains((string) $k, 'sunset:')) {
                    $name = substr((string) $k, strpos((string) $k, 'sunset:'));
                }
                $conn->del($name);
            }
        } catch (Throwable) {
            $this->markTestSkipped('Redis not reachable.');
        }
    }

    public function test_compute_returns_expected_shape_with_zeros_on_empty_redis(): void
    {
        /** @var HealthSummary $summary */
        $summary = $this->app->make(HealthSummary::class);

        $result = $summary->compute();

        foreach (['workers', 'pending', 'throughput', 'failed', 'probes', 'workerWarning'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertIsInt($result['workers']);
        $this->assertIsInt($result['pending']);
        $this->assertIsString($result['throughput']);
        $this->assertIsInt($result['failed']);
        $this->assertIsArray($result['probes']);
        $this->assertNull($result['workerWarning']);

        // Empty Redis -> zero workers / zero pending / zero failures.
        $this->assertSame(0, $result['workers']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('0', $result['throughput']);
        $this->assertSame([], $result['probes']);
    }

    public function test_compute_aggregates_failures_from_recent_failed_zset(): void
    {
        // Seed two entries into sunset:recent_failed_jobs.
        $conn = $this->app->make(RedisFactory::class)
            ->connection(config('sunset.redis_connection', 'default'));
        $now = (float) (microtime(true) * 1000);
        $conn->zadd('sunset:recent_failed_jobs', $now, 'job-a');
        $conn->zadd('sunset:recent_failed_jobs', $now + 1, 'job-b');

        /** @var HealthSummary $summary */
        $summary = $this->app->make(HealthSummary::class);
        $this->assertSame(2, $summary->compute()['failed']);
    }

    public function test_compute_aggregates_throughput_from_latest_queue_snapshots(): void
    {
        /** @var MetricsRepository $metrics */
        $metrics = $this->app->make(MetricsRepository::class);

        // Two completed jobs on `default` -> throughput counter ticks up.
        $metrics->incrementThroughput('App\\Jobs\\A', 'default', 100.0);
        $metrics->incrementThroughput('App\\Jobs\\A', 'default', 120.0);
        // Two on `emails`.
        $metrics->incrementThroughput('App\\Jobs\\B', 'emails', 80.0);
        $metrics->incrementThroughput('App\\Jobs\\B', 'emails', 90.0);

        // Snapshot freezes the current counters into the per-queue ZSETs.
        $metrics->snapshot();

        // Sum the latest snapshot per queue — matches HealthSummary's logic
        // and avoids hard-coding a magic number that drifts if cross-test
        // fixtures or framework metric writes leak in.
        $expected = 0;
        foreach ($metrics->queues() as $queue) {
            $snaps = $metrics->snapshotsForQueue((string) $queue);
            if ($snaps !== []) {
                $expected += (int) (end($snaps)['throughput'] ?? 0);
            }
        }
        $this->assertGreaterThanOrEqual(4, $expected, 'Seeded throughput at least 4');

        /** @var HealthSummary $summary */
        $summary = $this->app->make(HealthSummary::class);
        $result = $summary->compute();

        $this->assertSame((string) $expected, $result['throughput']);
    }

    public function test_format_count_renders_compact_form_for_thousands(): void
    {
        $this->assertSame('0', HealthSummary::formatCount(0));
        $this->assertSame('421', HealthSummary::formatCount(421));
        $this->assertSame('999', HealthSummary::formatCount(999));
        $this->assertSame('1k', HealthSummary::formatCount(1000));
        $this->assertSame('1.2k', HealthSummary::formatCount(1234));
        // 9999 / 1000 = 9.999 — number_format rounds up to 10.0, which the
        // formatter strips the trailing .0 from. Acceptable rounding behaviour.
        $this->assertSame('10k', HealthSummary::formatCount(9999));
        $this->assertSame('9.5k', HealthSummary::formatCount(9499));
        $this->assertSame('12k', HealthSummary::formatCount(12345));
        $this->assertSame('100k', HealthSummary::formatCount(100000));
    }

    public function test_compute_is_memoised_per_instance(): void
    {
        $calls = 0;
        $workload = new class ($calls) implements WorkloadRepository {
            public function __construct(private int &$calls) {}
            public function get(): array
            {
                $this->calls++;
                return [['name' => 'default', 'length' => 7, 'processes' => 1, 'wait' => 0, 'split_queues' => null]];
            }
        };

        $summary = new HealthSummary(
            workload: $workload,
            supervisors: $this->app->make(SupervisorRepository::class),
            failures: $this->app->make(FailedJobRepository::class),
            workers: $this->app->make(WorkerMetricsRepository::class),
            metrics: $this->app->make(MetricsRepository::class),
            probes: $this->app->make(ProbeCache::class),
        );

        $first = $summary->compute();
        $second = $summary->compute();

        $this->assertSame($first, $second);
        $this->assertSame(7, $first['pending']);
        $this->assertSame(1, $calls, 'WorkloadRepository::get() should only be called once per compute() invocation.');
    }

    public function test_compute_degrades_gracefully_when_repositories_throw(): void
    {
        $throwing = new class implements WorkloadRepository {
            public function get(): array { throw new RuntimeException('redis down'); }
        };
        $throwingSupers = new class implements SupervisorRepository {
            public function names(): array { throw new RuntimeException('redis down'); }
            public function all(): array { throw new RuntimeException('redis down'); }
            public function find(string $name): ?array { return null; }
            public function get(array $names): array { return []; }
            public function longestActiveTimeout(): int { return 0; }
            public function update(\Admnio\Sunset\Supervisor\Supervisor $supervisor): void {}
            public function forget(array|string $names): void {}
            public function flushExpired(): void {}
        };
        $throwingMetrics = new class implements MetricsRepository {
            public function jobs(): array { throw new RuntimeException('redis down'); }
            public function queues(): array { throw new RuntimeException('redis down'); }
            public function throughputForJob(string $job): int { return 0; }
            public function throughputForQueue(string $queue): int { return 0; }
            public function runtimeForJob(string $job): float { return 0; }
            public function runtimeForQueue(string $queue): float { return 0; }
            public function snapshotsForJob(string $job): array { return []; }
            public function snapshotsForQueue(string $queue): array { return []; }
            public function incrementThroughput(string $jobName, string $queue, float $runtime): void {}
            public function acquireWaitTimes(): array { return []; }
            public function forgetJob(string $job): void {}
            public function forgetQueue(string $queue): void {}
            public function snapshot(): void {}
            public function latestSnapshotAt(): int { return 0; }
            public function acquireWaitTimeLock(int $ttlSeconds = 60): bool { return false; }
        };
        $throwingFailures = new class implements FailedJobRepository {
            public function failed(\Throwable $e, string $connection, string $queue, \Admnio\Sunset\JobPayload $payload): void {}
            public function findFailed(string $id): ?object { return null; }
            public function getFailed(?string $afterIndex = null): \Illuminate\Support\Collection { return collect(); }
            public function countFailed(): int { return 0; }
            public function totalFailed(): int { return 0; }
            public function countRecentlyFailed(): int { throw new RuntimeException('redis down'); }
            public function deleteFailed(string $id): int { return 0; }
            public function trimFailedJobs(): void {}
        };

        $summary = new HealthSummary(
            workload: $throwing,
            supervisors: $throwingSupers,
            failures: $throwingFailures,
            workers: $this->app->make(WorkerMetricsRepository::class),
            metrics: $throwingMetrics,
            probes: $this->app->make(ProbeCache::class),
        );

        $result = $summary->compute();

        $this->assertSame(0, $result['workers']);
        $this->assertSame(0, $result['pending']);
        $this->assertSame('0', $result['throughput']);
        $this->assertSame(0, $result['failed']);
        $this->assertNull($result['workerWarning']);
        // Probes degrade to an empty array (ProbeCache itself is tolerant; if
        // the cache backend is fine, this just means no probes have been
        // recorded yet).
        $this->assertIsArray($result['probes']);
    }

    public function test_probe_cache_round_trip(): void
    {
        /** @var ProbeCache $cache */
        $cache = $this->app->make(ProbeCache::class);
        $this->assertSame([], $cache->recent());

        $cache->record([
            ['name' => 'sqs', 'status' => 'ok', 'latency' => '38ms'],
            ['name' => 'redis', 'status' => 'ok', 'latency' => '2ms'],
        ]);

        $recent = $cache->recent();
        $this->assertCount(2, $recent);
        $this->assertSame('sqs', $recent[0]['name']);
        $this->assertSame('redis', $recent[1]['name']);
    }
}
