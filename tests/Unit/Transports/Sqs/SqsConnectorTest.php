<?php

namespace Admnio\Sunset\Tests\Unit\Transports\Sqs;

use Admnio\Sunset\Transports\Sqs\SqsConnector;
use Admnio\Sunset\Transports\Sqs\SqsQueue;
use Admnio\Sunset\Tests\TestCase;

class SqsConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(SqsConnector::class, function ($app) {
            return new SqsConnector(
                container: $app,
                redis: $app->make(\Illuminate\Contracts\Redis\Factory::class),
                packageConfig: config('sunset'),
            );
        });
    }

    public function test_connect_returns_horizon_sqs_queue(): void
    {
        $connector = $this->app->make(SqsConnector::class);

        $queue = $connector->connect([
            'key' => 'test',
            'secret' => 'test',
            'region' => 'us-east-1',
            'prefix' => 'http://localhost:4566/000000000000',
            'queue' => 'default',
            'suffix' => '',
        ]);

        $this->assertInstanceOf(SqsQueue::class, $queue);
    }
}
