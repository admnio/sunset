<?php

namespace MasonWorkforce\HorizonSqs\Tests\Integration;

use Illuminate\Support\Facades\Queue;

class FifoOrderingTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLocalStackAvailable();
        $this->deleteAllQueues();
        $url = $this->createQueue('orders.fifo', fifo: true);
        config(['queue.connections.sqs.prefix' => str_replace('/orders.fifo', '', $url)]);
    }

    public function test_fifo_preserves_order_within_group(): void
    {
        $sqs = Queue::connection('sqs');

        $sqs->pushRaw('{"id":"1","seq":1,"_horizon_nonce":"a1"}', 'orders.fifo');
        $sqs->pushRaw('{"id":"2","seq":2,"_horizon_nonce":"a2"}', 'orders.fifo');
        $sqs->pushRaw('{"id":"3","seq":3,"_horizon_nonce":"a3"}', 'orders.fifo');

        $received = [];
        for ($i = 0; $i < 3; $i++) {
            $job = $sqs->pop('orders.fifo');
            $this->assertNotNull($job, "expected job #{$i}");
            $body = json_decode($job->getRawBody(), true);
            $received[] = $body['seq'];
            // FIFO queues block subsequent receives in the same MessageGroupId
            // until the in-flight message is deleted (or visibility expires).
            $job->delete();
        }

        $this->assertSame([1, 2, 3], $received);
    }
}
