<?php

namespace Admnio\Sunset\Tests\Unit\Transports\Sqs;

use Admnio\Sunset\Transports\Sqs\SqsQueue;
use Admnio\Sunset\Transports\Sqs\SqsTransport;
use Admnio\Sunset\Tests\TestCase;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Mockery;

class SqsTransportTest extends TestCase
{
    public function test_name_returns_sqs(): void
    {
        $transport = $this->makeTransport();
        $this->assertSame('sqs', $transport->name());
    }

    public function test_connect_returns_sqs_queue(): void
    {
        $transport = $this->makeTransport();

        $queue = $transport->connect([
            'key' => 'test',
            'secret' => 'test',
            'region' => 'us-east-1',
            'prefix' => 'http://localhost:4566/000000000000',
            'queue' => 'default',
            'suffix' => '',
            'wait_time' => 20,
        ]);

        $this->assertInstanceOf(SqsQueue::class, $queue);
    }

    public function test_workload_aggregates_queue_attributes(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('getQueueAttributesAsync')
            ->andReturnUsing(function ($args) {
                $name = basename($args['QueueUrl']);
                $promise = Mockery::mock(\GuzzleHttp\Promise\PromiseInterface::class);
                $promise->shouldReceive('wait')->andReturn(new Result([
                    'Attributes' => [
                        'ApproximateNumberOfMessages' => $name === 'orders' ? '40' : '10',
                        'ApproximateNumberOfMessagesNotVisible' => '0',
                    ],
                ]));
                return $promise;
            });

        $transport = new SqsTransport(
            container: $this->app,
            redis: $this->app->make(RedisFactory::class),
            packageConfig: config('sunset'),
            queuePrefix: 'http://localhost:4566/000000000000',
            sqsClient: $sqs,
        );

        $workload = $transport->workload(['orders', 'default']);

        $byName = collect($workload)->keyBy('name')->all();
        $this->assertSame(40, $byName['orders']['length']);
        $this->assertSame(10, $byName['default']['length']);
    }

    public function test_workload_logs_and_continues_on_failure(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('getQueueAttributesAsync')
            ->andReturnUsing(function ($args) {
                $name = basename($args['QueueUrl']);
                $promise = Mockery::mock(\GuzzleHttp\Promise\PromiseInterface::class);
                if ($name === 'broken') {
                    $promise->shouldReceive('wait')->andThrow(new \RuntimeException('boom'));
                } else {
                    $promise->shouldReceive('wait')->andReturn(new Result([
                        'Attributes' => ['ApproximateNumberOfMessages' => '5', 'ApproximateNumberOfMessagesNotVisible' => '0'],
                    ]));
                }
                return $promise;
            });

        $transport = new SqsTransport(
            container: $this->app,
            redis: $this->app->make(RedisFactory::class),
            packageConfig: config('sunset'),
            queuePrefix: 'http://localhost:4566/000000000000',
            sqsClient: $sqs,
        );

        $workload = $transport->workload(['orders', 'broken']);
        $byName = collect($workload)->keyBy('name')->all();

        $this->assertSame(5, $byName['orders']['length']);
        $this->assertSame(0, $byName['broken']['length']);
    }

    private function makeTransport(): SqsTransport
    {
        return new SqsTransport(
            container: $this->app,
            redis: $this->app->make(RedisFactory::class),
            packageConfig: config('sunset'),
            queuePrefix: 'http://localhost:4566/000000000000',
            sqsClient: null,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
