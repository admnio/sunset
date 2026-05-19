<?php

namespace Admnio\Sunset\Tests\Unit\Transports\Rabbit;

use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Events\JobReserved;
use Admnio\Sunset\Tests\TestCase;
use Admnio\Sunset\Transports\Rabbit\RabbitQueue;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobStore;
use Illuminate\Support\Facades\Event;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\QueueConfig;

class RabbitQueueTest extends TestCase
{
    public function test_push_raw_dispatches_sunset_events_around_send(): void
    {
        Event::fake([JobQueueing::class, JobQueued::class]);

        // Partial-mock the protected AMQP internals (declareDestination /
        // publishBasic) so parent::pushRaw() runs without hitting a real
        // broker. Our own pushRaw override stays live so we can verify the
        // events fire around the parent call.
        $queue = $this->makeQueueWithoutBroker([
            'declareDestination',
            'publishBasic',
        ]);
        $queue->shouldAllowMockingProtectedMethods();
        $queue->shouldReceive('declareDestination')->andReturnNull();
        $queue->shouldReceive('publishBasic')->andReturn(1);

        $queue->setConnectionName('rabbitmq');
        $queue->setContainer($this->app);

        $payload = json_encode(['id' => 'abc', 'displayName' => 'TestJob', 'data' => []]);

        $queue->pushRaw($payload, 'orders');

        Event::assertDispatched(JobQueueing::class, function ($e) {
            return $e->connectionName === 'rabbitmq' && $e->queue === 'orders';
        });
        Event::assertDispatched(JobQueued::class, function ($e) {
            return $e->connectionName === 'rabbitmq' && $e->queue === 'orders';
        });
    }

    public function test_pop_dispatches_job_reserved_when_a_job_is_returned(): void
    {
        Event::fake([JobReserved::class]);

        // Stub getChannel() so parent::pop() reads a synthetic AMQPMessage
        // off the channel and constructs a real RabbitMQJob. This is the
        // closest we can get to exercising the real override without a
        // live broker.
        $body = json_encode([
            'id' => 'xyz',
            'displayName' => 'TestJob',
            'data' => [],
            'attempts' => 0,
        ]);
        $message = new AMQPMessage($body);

        $queue = $this->makeQueueWithoutBroker(['getChannel']);
        $queue->shouldAllowMockingProtectedMethods();

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_get')->with('orders')->andReturn($message);
        $queue->shouldReceive('getChannel')->andReturn($channel);

        $queue->setConnectionName('rabbitmq');
        $queue->setContainer($this->app);

        $result = $queue->pop('orders');

        $this->assertInstanceOf(RabbitMQJob::class, $result);
        Event::assertDispatched(JobReserved::class, function ($e) {
            return $e->connectionName === 'rabbitmq' && $e->queue === 'orders';
        });
    }

    public function test_pop_does_not_dispatch_reserved_when_queue_is_empty(): void
    {
        Event::fake([JobReserved::class]);

        $queue = $this->makeQueueWithoutBroker(['getChannel']);
        $queue->shouldAllowMockingProtectedMethods();

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_get')->with('orders')->andReturn(null);
        $queue->shouldReceive('getChannel')->andReturn($channel);

        $queue->setConnectionName('rabbitmq');
        $queue->setContainer($this->app);

        $result = $queue->pop('orders');

        $this->assertNull($result);
        Event::assertNotDispatched(JobReserved::class);
    }

    public function test_later_writes_to_delayed_job_store_and_skips_amqp(): void
    {
        $store = Mockery::mock(DelayedJobStore::class);
        $store->shouldReceive('buffer')
            ->once()
            ->withArgs(function (string $queueName, string $payload, float $eta) {
                $decoded = json_decode($payload, true);
                return $queueName === 'orders'
                    && is_array($decoded)
                    && $eta >= (float) time();
            });

        $this->app->instance(DelayedJobStore::class, $store);

        // Partial mock that asserts the AMQP publish path is never invoked.
        // If later() ever calls parent::laterRaw() or parent::pushRaw() it
        // would hit declareDestination / publishBasic / batch_basic_publish,
        // all of which we assert as never-called.
        $queue = $this->makeQueueWithoutBroker([
            'declareDestination',
            'declareQueue',
            'publishBasic',
            'laterRaw',
        ]);
        $queue->shouldAllowMockingProtectedMethods();
        $queue->shouldNotReceive('declareDestination');
        $queue->shouldNotReceive('declareQueue');
        $queue->shouldNotReceive('publishBasic');
        $queue->shouldNotReceive('laterRaw');

        $queue->setConnectionName('rabbitmq');
        $queue->setContainer($this->app);

        $id = $queue->later(60, new \stdClass(), '', 'orders');

        $this->assertIsString($id);
        $this->assertSame(40, strlen($id));
    }

    /**
     * Build a {@see RabbitQueue} partial-mock with no live AMQP broker. The
     * AMQP connection itself is a mock so any accidental passthrough call
     * lands on Mockery rather than crashing.
     *
     * @param  list<string>  $stubMethods  Method names to stub via Mockery
     *   partial-mock (you can chain `->shouldReceive(...)` afterwards).
     */
    private function makeQueueWithoutBroker(array $stubMethods = []): RabbitQueue
    {
        $queueConfig = (new QueueConfig())->setQueue('default');

        if (! empty($stubMethods)) {
            $mockSpec = sprintf('%s[%s]', RabbitQueue::class, implode(',', $stubMethods));
            /** @var RabbitQueue|\Mockery\MockInterface $queue */
            $queue = Mockery::mock($mockSpec, [$queueConfig, []]);
        } else {
            /** @var RabbitQueue|\Mockery\MockInterface $queue */
            $queue = Mockery::mock(RabbitQueue::class, [$queueConfig, []])->makePartial();
        }

        $conn = Mockery::mock(AbstractConnection::class);
        $conn->shouldReceive('close')->andReturnNull();
        $queue->setConnection($conn);

        return $queue;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
