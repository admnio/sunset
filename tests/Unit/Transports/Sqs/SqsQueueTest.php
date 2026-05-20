<?php

namespace Admnio\Sunset\Tests\Unit\Transports\Sqs;

use Aws\Sqs\SqsClient;
use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobReserved;
use Admnio\Sunset\RateLimiting\Decision;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\RateLimiting\RateLimitGate;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobStore;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Support\Facades\Event;
use Admnio\Sunset\Transports\Sqs\SqsQueue;
use Admnio\Sunset\Transports\Sqs\Payload\ExtendedPayloadHandler;
use Admnio\Sunset\Transports\Sqs\FifoMessageAttributes;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class SqsQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset the singleton registry so each test starts with no limits.
        $this->app->forgetInstance(LimitRegistry::class);
    }

    public function test_create_payload_includes_id_field(): void
    {
        $queue = $this->makeQueue();
        $queue->setContainer($this->app);

        // createPayloadArray runs through parent then we set id = uuid; the final
        // JobPayload::prepare runs inside pushRaw, not createPayload, so we only
        // assert the base shape (id, uuid, displayName) is present here.
        $json = $queue->createPayload('Illuminate\\Queue\\CallQueuedHandler@call', 'default', new \stdClass());
        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('id', $decoded);
        $this->assertArrayHasKey('uuid', $decoded);
        $this->assertSame($decoded['uuid'], $decoded['id']);
    }

    public function test_push_raw_sends_to_sqs_and_fires_sunset_events(): void
    {
        Event::fake();

        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(function ($args) {
                $body = json_decode($args['MessageBody'], true);
                // JobPayload::prepare adds type/tags/pushedAt; pre-existing id is preserved.
                return $args['QueueUrl'] === 'http://localhost:4566/000000000000/default'
                    && $body['id'] === 'abc'
                    && array_key_exists('type', $body)
                    && array_key_exists('tags', $body)
                    && array_key_exists('pushedAt', $body)
                    && ! isset($args['DelaySeconds']);
            }))
            ->andReturn(new \Aws\Result(['MessageId' => 'mid-1']));

        $queue = $this->makeQueueWithSqs($sqs);
        $queue->setContainer($this->app);

        $result = $queue->pushRaw('{"id":"abc"}', 'default');

        $this->assertSame('mid-1', $result);

        Event::assertDispatched(JobQueueing::class, function ($event) {
            return is_string($event->connectionName);
        });
        Event::assertDispatched(JobQueued::class);
    }

    public function test_later_buffers_long_delay_in_redis_and_fires_events(): void
    {
        Event::fake();

        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldNotReceive('sendMessage');

        $store = Mockery::mock(DelayedJobStore::class);
        $store->shouldReceive('buffer')
            ->once()
            ->with(
                'default',
                // SqsQueue passes its connection name so the reenqueuer can
                // route the swept job back to SQS. With no container-set
                // connection name in this unit test, SqsQueue falls back to
                // the literal 'sqs'.
                'sqs',
                Mockery::on(function ($p) {
                    $decoded = json_decode($p, true);
                    return is_array($decoded)
                        && isset($decoded['id'])
                        && array_key_exists('pushedAt', $decoded);
                }),
                Mockery::on(fn ($eta) => $eta > microtime(true) + 3500)
            );

        $queue = new SqsQueue(
            sqs: $sqs,
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: null,
            delayedStore: $store,
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );
        $queue->setContainer($this->app);

        $result = $queue->later(3600, 'App\\Jobs\\Noop', '', 'default');
        $this->assertIsString($result); // returned UUID id from buffered payload

        Event::assertDispatched(JobQueueing::class);
        Event::assertDispatched(JobQueued::class);
    }

    public function test_push_raw_includes_fifo_attributes_for_fifo_queue(): void
    {
        Event::fake();

        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['QueueUrl'] === 'http://localhost:4566/000000000000/orders.fifo'
                    && $args['MessageGroupId'] === 'orders.fifo'
                    && isset($args['MessageDeduplicationId']);
            }))
            ->andReturn(new \Aws\Result(['MessageId' => 'mid-2']));

        $queue = $this->makeQueueWithSqs($sqs);
        $queue->setContainer($this->app);

        $result = $queue->pushRaw('{"id":"abc"}', 'orders.fifo');
        $this->assertSame('mid-2', $result);
    }

    public function test_push_raw_spills_large_payload_to_s3(): void
    {
        Event::fake();

        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(function ($args) {
                $body = json_decode($args['MessageBody'], true);
                return isset($body['s3PointerKey']);
            }))
            ->andReturn(new \Aws\Result(['MessageId' => 'mid-3']));

        $extended = Mockery::mock(ExtendedPayloadHandler::class);
        $extended->shouldReceive('maybeStore')
            ->once()
            ->andReturn('{"s3PointerKey":"sunset-payloads/abc","size":300000}');

        $queue = new SqsQueue(
            sqs: $sqs,
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: $extended,
            delayedStore: Mockery::mock(DelayedJobStore::class),
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );
        $queue->setContainer($this->app);

        $result = $queue->pushRaw('{"id":"abc","data":"' . str_repeat('a', 300_000) . '"}', 'default');
        $this->assertSame('mid-3', $result);
    }

    public function test_pop_unwraps_extended_payload_and_fires_job_reserved(): void
    {
        Event::fake();

        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new \Aws\Result([
                'Messages' => [[
                    'MessageId' => 'mid-1',
                    'ReceiptHandle' => 'rh-1',
                    'Body' => '{"s3PointerKey":"sunset-payloads/abc","size":300000}',
                    'Attributes' => ['ApproximateReceiveCount' => 1],
                ]],
            ]));

        $extended = Mockery::mock(ExtendedPayloadHandler::class);
        $extended->shouldReceive('maybeFetch')
            ->once()
            ->andReturn('{"id":"abc","tags":[]}');

        $queue = new SqsQueue(
            sqs: $sqs,
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: $extended,
            delayedStore: Mockery::mock(DelayedJobStore::class),
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );
        $queue->setContainer($this->app);

        $job = $queue->pop('default');

        $this->assertNotNull($job);
        $this->assertSame('{"id":"abc","tags":[]}', $job->getRawBody());
        Event::assertDispatched(JobReserved::class);
    }

    /**
     * With NO Sunset::for() limits registered, the gate's empty-registry
     * short-circuit returns Decision::admit() without touching Redis, so
     * pop() returns the job unchanged. Exercises the real gate (no mock)
     * to confirm the zero-overhead path through actual production code.
     */
    public function test_pop_returns_job_unchanged_when_no_limits_registered(): void
    {
        Event::fake([JobReserved::class]);

        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new \Aws\Result([
                'Messages' => [[
                    'MessageId' => 'mid-1',
                    'ReceiptHandle' => 'rh-1',
                    'Body' => '{"id":"abc","tags":[]}',
                    'Attributes' => ['ApproximateReceiveCount' => 1],
                ]],
            ]));

        $queue = $this->makeQueueWithSqs($sqs);
        $queue->setContainer($this->app);

        $job = $queue->pop('default');

        $this->assertNotNull($job);
        $this->assertSame('{"id":"abc","tags":[]}', $job->getRawBody());
    }

    /**
     * With a registered limit that REJECTS, pop() must return null because
     * the gate has already taken ownership of the job (release/fail/delete).
     * Uses a mock gate to isolate the transport's pop() wiring — proving the
     * gate is invoked with the right shape AND that pop() honors the
     * Decision::reject() result. The real-Redis gate-rejection roundtrip
     * lives in B8 integration tests.
     */
    public function test_pop_returns_null_when_gate_rejects(): void
    {
        Event::fake([JobReserved::class]);

        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new \Aws\Result([
                'Messages' => [[
                    'MessageId' => 'mid-1',
                    'ReceiptHandle' => 'rh-1',
                    'Body' => '{"id":"abc","tags":["billing"]}',
                    'Attributes' => ['ApproximateReceiveCount' => 1],
                ]],
            ]));

        $capturedPayload = null;
        $capturedQueue = null;
        $gate = Mockery::mock(RateLimitGate::class);
        $gate->shouldReceive('admit')
            ->once()
            ->withArgs(function (JobContract $job, array $payload, string $queueArg, array $tags)
                use (&$capturedPayload, &$capturedQueue) {
                $capturedPayload = $payload;
                $capturedQueue = $queueArg;
                return true;
            })
            ->andReturnUsing(function (JobContract $job) {
                // Real gate would call release/fail/delete here. We can't
                // ack on the mocked SqsClient, so just return a Decision::reject.
                return Decision::reject(30);
            });

        $this->app->instance(RateLimitGate::class, $gate);

        $queue = $this->makeQueueWithSqs($sqs);
        $queue->setContainer($this->app);

        $result = $queue->pop('default');
        $this->assertNull($result);

        // Verify the payload shape passed to the gate.
        $this->assertIsArray($capturedPayload);
        $this->assertSame(['billing'], $capturedPayload['tags']);
        $this->assertSame('default', $capturedQueue);
        $this->assertArrayHasKey('connection', $capturedPayload);
    }

    private function makeQueueWithSqs(SqsClient $sqs): SqsQueue
    {
        return new SqsQueue(
            sqs: $sqs,
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: null,
            delayedStore: Mockery::mock(DelayedJobStore::class),
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );
    }

    private function makeQueue(): SqsQueue
    {
        return new SqsQueue(
            sqs: Mockery::mock(SqsClient::class),
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: null,
            delayedStore: Mockery::mock(DelayedJobStore::class),
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
