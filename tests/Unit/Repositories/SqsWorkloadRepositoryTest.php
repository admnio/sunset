<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Repositories;

use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\ProcessRepository;
use MasonWorkforce\HorizonSqs\Repositories\SqsWorkloadRepository;
use MasonWorkforce\HorizonSqs\Tests\TestCase;
use Mockery;

class SqsWorkloadRepositoryTest extends TestCase
{
    public function test_returns_queues_with_length_and_wait(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('getQueueAttributesAsync')
            ->andReturnUsing(function ($args) {
                $queue = basename($args['QueueUrl']);
                $promise = Mockery::mock(\GuzzleHttp\Promise\PromiseInterface::class);
                $promise->shouldReceive('wait')->andReturn(new Result([
                    'Attributes' => [
                        'ApproximateNumberOfMessages' => $queue === 'orders' ? '40' : '10',
                        'ApproximateNumberOfMessagesNotVisible' => '0',
                    ],
                ]));
                return $promise;
            });

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('runtimeForQueue')->with('orders')->andReturn(2.0);
        $metrics->shouldReceive('runtimeForQueue')->with('default')->andReturn(1.0);

        $processes = Mockery::mock(ProcessRepository::class);
        $processes->shouldReceive('processesPerQueue')->andReturn(['orders' => 4, 'default' => 2]);

        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('remember')->andReturnUsing(fn ($key, $ttl, $cb) => $cb());

        $repo = new SqsWorkloadRepository(
            $sqs,
            $metrics,
            $processes,
            $cache,
            'http://localhost:4566/000000000000',
            ['orders', 'default'],
            5
        );

        $workload = $repo->get();

        $byName = collect($workload)->keyBy('name')->all();

        $this->assertSame(40, $byName['orders']['length']);
        $this->assertSame(20, $byName['orders']['wait']); // 40 * 2.0 / 4 = 20
        $this->assertSame(10, $byName['default']['length']);
        $this->assertSame(5, $byName['default']['wait']);  // 10 * 1.0 / 2 = 5
    }

    public function test_handles_zero_processes(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('getQueueAttributesAsync')
            ->andReturnUsing(function ($args) {
                $promise = Mockery::mock(\GuzzleHttp\Promise\PromiseInterface::class);
                $promise->shouldReceive('wait')->andReturn(new Result([
                    'Attributes' => ['ApproximateNumberOfMessages' => '5', 'ApproximateNumberOfMessagesNotVisible' => '0'],
                ]));
                return $promise;
            });

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('runtimeForQueue')->andReturn(1.0);

        $processes = Mockery::mock(ProcessRepository::class);
        $processes->shouldReceive('processesPerQueue')->andReturn(['default' => 0]);

        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('remember')->andReturnUsing(fn ($key, $ttl, $cb) => $cb());

        $repo = new SqsWorkloadRepository($sqs, $metrics, $processes, $cache, 'http://localhost:4566/000000000000', ['default'], 5);

        $workload = $repo->get();

        $this->assertSame(5, $workload[0]['wait']); // divides by max(1, processes)
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
