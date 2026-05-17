<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Queue;

use MasonWorkforce\HorizonSqs\Queue\HorizonSqsConnector;
use MasonWorkforce\HorizonSqs\Queue\HorizonSqsQueue;
use MasonWorkforce\HorizonSqs\Tests\TestCase;

class HorizonSqsConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(HorizonSqsConnector::class, function ($app) {
            return new HorizonSqsConnector(
                container: $app,
                redis: $app->make(\Illuminate\Contracts\Redis\Factory::class),
                packageConfig: config('horizon-sqs'),
            );
        });
    }

    public function test_connect_returns_horizon_sqs_queue(): void
    {
        $connector = $this->app->make(HorizonSqsConnector::class);

        $queue = $connector->connect([
            'key' => 'test',
            'secret' => 'test',
            'region' => 'us-east-1',
            'prefix' => 'http://localhost:4566/000000000000',
            'queue' => 'default',
            'suffix' => '',
        ]);

        $this->assertInstanceOf(HorizonSqsQueue::class, $queue);
    }
}
