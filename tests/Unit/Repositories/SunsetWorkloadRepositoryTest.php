<?php

namespace Admnio\Sunset\Tests\Unit\Repositories;

use Admnio\Sunset\Contracts\Transport;
use Admnio\Sunset\Repositories\SunsetWorkloadRepository;
use Admnio\Sunset\Support\TransportRegistry;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Cache\Repository as Cache;
use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Mockery;

class SunsetWorkloadRepositoryTest extends TestCase
{
    public function test_returns_queues_with_length_processes_and_wait(): void
    {
        $transport = Mockery::mock(Transport::class);
        $transport->shouldReceive('name')->andReturn('sqs');
        $transport->shouldReceive('workload')->with(['orders', 'default'])->andReturn([
            ['name' => 'orders', 'length' => 40, 'wait' => 0, 'processes' => 0, 'split_queues' => null],
            ['name' => 'default', 'length' => 10, 'wait' => 0, 'processes' => 0, 'split_queues' => null],
        ]);
        $registry = new TransportRegistry();
        $registry->register($transport);

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('runtimeForQueue')->with('orders')->andReturn(2.0);
        $metrics->shouldReceive('runtimeForQueue')->with('default')->andReturn(1.0);

        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn([
            (object) ['processes' => ['sqs:orders' => 4, 'sqs:default' => 2]],
        ]);

        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('remember')->andReturnUsing(fn ($k, $t, $cb) => $cb());

        $repo = new SunsetWorkloadRepository(
            $registry, $metrics, $supervisors, $cache, ['orders', 'default'], 5
        );

        $workload = $repo->get();
        $byName = collect($workload)->keyBy('name')->all();

        $this->assertSame(40, $byName['orders']['length']);
        $this->assertSame(20, $byName['orders']['wait']);  // 40 * 2.0 / 4
        $this->assertSame(10, $byName['default']['length']);
        $this->assertSame(5, $byName['default']['wait']);   // 10 * 1.0 / 2
    }

    public function test_handles_zero_processes(): void
    {
        $transport = Mockery::mock(Transport::class);
        $transport->shouldReceive('name')->andReturn('sqs');
        $transport->shouldReceive('workload')->andReturn([
            ['name' => 'default', 'length' => 5, 'wait' => 0, 'processes' => 0, 'split_queues' => null],
        ]);
        $registry = new TransportRegistry();
        $registry->register($transport);

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('runtimeForQueue')->andReturn(1.0);

        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn([]);

        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('remember')->andReturnUsing(fn ($k, $t, $cb) => $cb());

        $repo = new SunsetWorkloadRepository(
            $registry, $metrics, $supervisors, $cache, ['default'], 5
        );

        $workload = $repo->get();
        $this->assertSame(5, $workload[0]['wait']);  // max(1, 0)
    }

    public function test_caches_via_cache_repository(): void
    {
        $transport = Mockery::mock(Transport::class);
        $transport->shouldReceive('name')->andReturn('sqs');
        $transport->shouldNotReceive('workload');

        $registry = new TransportRegistry();
        $registry->register($transport);

        $metrics = Mockery::mock(MetricsRepository::class);
        $supervisors = Mockery::mock(SupervisorRepository::class);

        $cache = Mockery::mock(Cache::class);
        $cached = [['name' => 'cached', 'length' => 0, 'wait' => 0, 'processes' => 1, 'split_queues' => null]];
        $cache->shouldReceive('remember')->with('sunset:workload', 5, Mockery::any())->andReturn($cached);

        $repo = new SunsetWorkloadRepository(
            $registry, $metrics, $supervisors, $cache, ['default'], 5
        );

        $this->assertSame($cached, $repo->get());
    }

    public function test_sums_processes_across_connections_for_same_queue(): void
    {
        $transport = Mockery::mock(Transport::class);
        $transport->shouldReceive('name')->andReturn('sqs');
        $transport->shouldReceive('workload')->with(['orders'])->andReturn([
            ['name' => 'orders', 'length' => 50, 'wait' => 0, 'processes' => 0, 'split_queues' => null],
        ]);

        $registry = new TransportRegistry();
        $registry->register($transport);

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('runtimeForQueue')->with('orders')->andReturn(1.0);

        // Two supervisor records both mention queue 'orders' but via different connection prefixes.
        // Total processes for 'orders' = 3 + 2 = 5. Wait = round(50 * 1.0 / 5) = 10.
        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn([
            (object) ['processes' => ['sqs:orders' => 3]],
            (object) ['processes' => ['redis:orders' => 2]],
        ]);

        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('remember')->andReturnUsing(fn ($k, $t, $cb) => $cb());

        $repo = new SunsetWorkloadRepository(
            $registry, $metrics, $supervisors, $cache, ['orders'], 5
        );

        $workload = $repo->get();
        $this->assertSame(5, $workload[0]['processes']);
        $this->assertSame(10, $workload[0]['wait']);
    }

    public function test_merges_workload_across_multiple_transports(): void
    {
        $sqsTransport = Mockery::mock(Transport::class);
        $sqsTransport->shouldReceive('name')->andReturn('sqs');
        $sqsTransport->shouldReceive('workload')->with(['orders', 'default'])->andReturn([
            ['name' => 'orders', 'length' => 40, 'wait' => 0, 'processes' => 0, 'split_queues' => null],
            ['name' => 'default', 'length' => 0, 'wait' => 0, 'processes' => 0, 'split_queues' => null],
        ]);

        $redisTransport = Mockery::mock(Transport::class);
        $redisTransport->shouldReceive('name')->andReturn('redis');
        $redisTransport->shouldReceive('workload')->with(['orders', 'default'])->andReturn([
            ['name' => 'orders', 'length' => 0, 'wait' => 0, 'processes' => 0, 'split_queues' => null],
            ['name' => 'default', 'length' => 10, 'wait' => 0, 'processes' => 0, 'split_queues' => null],
        ]);

        $registry = new TransportRegistry();
        $registry->register($sqsTransport);
        $registry->register($redisTransport);

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('runtimeForQueue')->andReturn(1.0);

        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn([
            (object) ['processes' => ['sqs:orders' => 2, 'redis:default' => 2]],
        ]);

        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('remember')->andReturnUsing(fn ($k, $t, $cb) => $cb());

        $repo = new SunsetWorkloadRepository(
            $registry, $metrics, $supervisors, $cache, ['orders', 'default'], 5
        );

        $workload = $repo->get();
        $byName = collect($workload)->keyBy('name')->all();

        $this->assertSame(40, $byName['orders']['length']);   // 40 (sqs) + 0 (redis)
        $this->assertSame(10, $byName['default']['length']); // 0 (sqs) + 10 (redis)
    }

    public function test_tags_each_row_with_source_transport_as_connection(): void
    {
        // v1.3.0: workload rows expose a `connection` field so the dashboard's
        // per-row pause/resume button knows which (connection, queue) pair to
        // hit. Transports themselves don't populate this — SunsetWorkloadRepository
        // stamps it during the merge using the transport's registered name.
        $sqsTransport = Mockery::mock(Transport::class);
        $sqsTransport->shouldReceive('name')->andReturn('sqs');
        $sqsTransport->shouldReceive('workload')->with(['orders'])->andReturn([
            ['name' => 'orders', 'length' => 7, 'wait' => 0, 'processes' => 0, 'split_queues' => null],
        ]);

        $registry = new TransportRegistry();
        $registry->register($sqsTransport);

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('runtimeForQueue')->andReturn(1.0);

        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn([]);

        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('remember')->andReturnUsing(fn ($k, $t, $cb) => $cb());

        $repo = new SunsetWorkloadRepository(
            $registry, $metrics, $supervisors, $cache, ['orders'], 5
        );

        $workload = $repo->get();
        $this->assertSame('sqs', $workload[0]['connection']);
    }

    public function test_only_queries_transports_within_the_provided_scope(): void
    {
        $database = Mockery::mock(Transport::class);
        $database->shouldReceive('name')->andReturn('database');
        $database->shouldReceive('workload')->with(['default'])->andReturn([
            ['name' => 'default', 'length' => 3, 'wait' => 0, 'processes' => 0, 'split_queues' => null],
        ]);

        // The SQS transport is registered but out of scope — it must NOT be
        // queried. Querying an unused/unreachable backend (e.g. SQS or RabbitMQ
        // on a database-only deployment) is what stalled the dashboard on
        // connect timeouts. shouldNotReceive() fails the test if it's touched.
        $sqs = Mockery::mock(Transport::class);
        $sqs->shouldReceive('name')->andReturn('sqs');
        $sqs->shouldNotReceive('workload');

        $registry = new TransportRegistry();
        $registry->register($database);
        $registry->register($sqs);

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('runtimeForQueue')->andReturn(1.0);

        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn([]);

        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('remember')->andReturnUsing(fn ($k, $t, $cb) => $cb());

        $repo = new SunsetWorkloadRepository(
            $registry, $metrics, $supervisors, $cache, ['default'], 5, ['database']
        );

        $workload = $repo->get();
        $byName = collect($workload)->keyBy('name')->all();

        $this->assertArrayHasKey('default', $byName);
        $this->assertSame(3, $byName['default']['length']);
        $this->assertSame('database', $byName['default']['connection']);
    }

    public function test_empty_scope_falls_back_to_all_registered_transports(): void
    {
        $redis = Mockery::mock(Transport::class);
        $redis->shouldReceive('name')->andReturn('redis');
        $redis->shouldReceive('workload')->with(['default'])->andReturn([
            ['name' => 'default', 'length' => 9, 'wait' => 0, 'processes' => 0, 'split_queues' => null],
        ]);

        $registry = new TransportRegistry();
        $registry->register($redis);

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('runtimeForQueue')->andReturn(1.0);

        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn([]);

        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('remember')->andReturnUsing(fn ($k, $t, $cb) => $cb());

        // No scope passed (legacy 6-arg construction) → query everything.
        $repo = new SunsetWorkloadRepository(
            $registry, $metrics, $supervisors, $cache, ['default'], 5
        );

        $workload = $repo->get();
        $this->assertSame(9, $workload[0]['length']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
