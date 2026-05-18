<?php

namespace Admnio\Sunset\Tests\Unit\Transports\Sqs;

use Admnio\Sunset\Contracts\Transport;
use Admnio\Sunset\Support\TransportRegistry;
use Admnio\Sunset\Transports\Sqs\SqsConnector;
use Admnio\Sunset\Transports\Sqs\SqsQueue;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class SqsConnectorTest extends TestCase
{
    public function test_connect_delegates_to_sqs_transport(): void
    {
        $config = [
            'key' => 'test',
            'secret' => 'test',
            'region' => 'us-east-1',
            'prefix' => 'http://localhost:4566/000000000000',
            'queue' => 'default',
            'suffix' => '',
            'wait_time' => 20,
        ];

        $expected = Mockery::mock(SqsQueue::class);

        $transport = Mockery::mock(Transport::class);
        $transport->shouldReceive('name')->andReturn('sqs');
        $transport->shouldReceive('connect')->with($config)->once()->andReturn($expected);

        $registry = new TransportRegistry();
        $registry->register($transport);

        $this->app->instance(TransportRegistry::class, $registry);

        $connector = $this->app->make(SqsConnector::class);
        $queue = $connector->connect($config);

        $this->assertSame($expected, $queue);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
