<?php

namespace Admnio\Sunset\Tests\Integration\RateLimiting;

use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\Tests\Fixtures\Jobs\RecordingJob;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Queue;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v0.7.0 — proves the same Sunset::for(...)->throttle(perMinute: 3) rule
 * behaves identically against the three transports (Redis, SQS, RabbitMQ).
 * Each leg pushes 5 jobs and expects exactly 3 admits + 2 releases, since
 * the throttle bookkeeping lives in Redis regardless of transport.
 */
class TransportParityTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->forgetInstance(LimitRegistry::class);
        $this->purgeRedisRlState();
        @unlink(sys_get_temp_dir() . '/sunset-marker');
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeRedisRlState();
        } catch (\Throwable $e) {
            // best-effort
        }
        @unlink(sys_get_temp_dir() . '/sunset-marker');
        parent::tearDown();
    }

    public static function transportProvider(): array
    {
        return [
            'redis' => ['redis'],
            'sqs' => ['sqs'],
            'rabbitmq' => ['rabbitmq'],
        ];
    }

    #[DataProvider('transportProvider')]
    public function test_throttle_behaves_identically_across_redis_sqs_rabbitmq(string $connection): void
    {
        $queue = "rl-parity-{$connection}";

        $this->prepareTransport($connection, $queue);

        Sunset::for($queue)->throttle(perMinute: 3);

        for ($i = 0; $i < 5; $i++) {
            Queue::connection($connection)->push(new RecordingJob("parity-{$connection}-{$i}"));
        }

        // SQS in LocalStack needs a beat to make pushed messages visible.
        if ($connection === 'sqs') {
            usleep(200_000);
        }

        $admitted = 0;
        $released = 0;
        $jobs = [];

        // Pop until we have accounted for all 5 jobs (admit or gate-release)
        // or until we exhaust the retry budget. SQS in particular can return
        // null for a transient invisibility window even when messages exist;
        // we retry within a bounded window so that null only ever counts as
        // a gate-release, not as "broker hiccup".
        $attempts = 0;
        $maxAttempts = 5 * 6;
        while ($admitted + $released < 5 && $attempts < $maxAttempts) {
            $attempts++;
            $job = Queue::connection($connection)->pop($queue);
            if ($job === null) {
                if ($connection === 'sqs') {
                    // Distinguish broker hiccup from gate-release: peek the
                    // pending counter Sunset increments on every reject.
                    if ($this->getRejectCount($connection, $queue) > $released) {
                        $released++;
                        continue;
                    }
                    usleep(200_000);
                    continue;
                }
                $released++;
                continue;
            }
            $admitted++;
            $jobs[] = $job;
        }

        // Clean up admitted jobs so they don't leak between data-provider rows.
        foreach ($jobs as $j) {
            try {
                $j->delete();
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $this->cleanupTransport($connection, $queue);

        $this->assertSame(
            3,
            $admitted,
            "[{$connection}] Expected exactly 3 admits against perMinute:3; got {$admitted}."
        );
        $this->assertSame(
            2,
            $released,
            "[{$connection}] Expected exactly 2 throttle-releases against perMinute:3; got {$released}."
        );
    }

    private function prepareTransport(string $connection, string $queue): void
    {
        switch ($connection) {
            case 'redis':
                config(['queue.connections.redis.queue' => $queue]);
                $this->purgeRedisQueue($queue);
                break;
            case 'sqs':
                $this->ensureLocalStackAvailable();
                // LocalStack uses the SQS prefix to compose queue URLs; create
                // the queue under whatever prefix is currently configured.
                $url = $this->createQueue($queue);
                $prefix = str_replace("/{$queue}", '', $url);
                config([
                    'queue.connections.sqs.prefix' => $prefix,
                    'queue.connections.sqs.queue' => $queue,
                ]);
                break;
            case 'rabbitmq':
                $this->ensureRabbitMQAvailable();
                config(['queue.connections.rabbitmq.queue' => $queue]);
                $this->declareAndPurgeRabbitQueue($queue);
                break;
        }
    }

    private function cleanupTransport(string $connection, string $queue): void
    {
        try {
            switch ($connection) {
                case 'redis':
                    $this->purgeRedisQueue($queue);
                    break;
                case 'sqs':
                    $this->sqs->deleteQueue([
                        'QueueUrl' => $this->sqs->getQueueUrl(['QueueName' => $queue])->get('QueueUrl'),
                    ]);
                    break;
                case 'rabbitmq':
                    $this->declareAndPurgeRabbitQueue($queue);
                    break;
            }
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    private function declareAndPurgeRabbitQueue(string $name): void
    {
        $connection = new AMQPStreamConnection(
            env('RABBITMQ_HOST', '127.0.0.1'),
            (int) env('RABBITMQ_PORT', 5672),
            env('RABBITMQ_USER', 'guest'),
            env('RABBITMQ_PASSWORD', 'guest'),
            env('RABBITMQ_VHOST', '/'),
        );

        try {
            $channel = $connection->channel();
            try {
                $channel->queue_declare($name, false, true, false, false);
                $channel->queue_bind($name, 'amq.direct', $name);
                $channel->queue_purge($name);
            } finally {
                $channel->close();
            }
        } finally {
            $connection->close();
        }
    }

    private function purgeRedisQueue(string $name): void
    {
        /** @var RedisFactory $factory */
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');
        $conn->del("queues:{$name}");
        $conn->del("queues:{$name}:delayed");
        $conn->del("queues:{$name}:reserved");
        $conn->del("queues:{$name}:notify");
    }

    /**
     * Read the RateLimitGate's internal reject counter for this connection +
     * queue. Used by the SQS leg to distinguish "broker returned no message
     * yet" from "the gate just released a message" — the latter increments
     * the counter, the former does not.
     */
    private function getRejectCount(string $connection, string $queue): int
    {
        /** @var RedisFactory $factory */
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');
        $key = "sunset:rl:rejects:{$connection}:{$queue}:queue:{$queue}";
        return (int) $conn->get($key);
    }

    private function purgeRedisRlState(): void
    {
        /** @var RedisFactory $factory */
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');

        $prefix = $this->detectPrefix($conn);

        foreach ((array) $conn->keys('sunset:rl:*') as $key) {
            $bare = ($prefix !== '' && str_starts_with($key, $prefix))
                ? substr($key, strlen($prefix))
                : $key;
            $conn->del($bare);
        }
    }

    private function detectPrefix($conn): string
    {
        try {
            return (string) $conn->client()->getOption(\Redis::OPT_PREFIX);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
