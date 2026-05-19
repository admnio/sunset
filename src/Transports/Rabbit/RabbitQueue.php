<?php

namespace Admnio\Sunset\Transports\Rabbit;

use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Events\JobReserved;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobStore;
use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Str;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\QueueConfig;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue as VendorQueue;

/**
 * Sunset's RabbitMQ queue driver.
 *
 * Mirrors the shape of {@see \Admnio\Sunset\Transports\Redis\RedisQueue}:
 *  - push/pushRaw fire JobQueueing then JobQueued around the parent publish
 *  - pop fires JobReserved when a job is returned
 *  - later() does NOT use the vendor delayed-queue (per-TTL holding queues)
 *    and instead writes to {@see DelayedJobStore}. RabbitMQ has no native
 *    sorted-set delay; we deliberately avoid the delayed-exchange plugin so
 *    Sunset's reaper is the single source of truth for delayed jobs across
 *    all transports.
 *
 * The parent vendor queue only accepts a {@see QueueConfig} object. The
 * AMQP connection itself is attached after construction via
 * {@see VendorQueue::setConnection()} — handled by the RabbitTransport.
 */
class RabbitQueue extends VendorQueue
{
    private mixed $lastPushed = null;

    /**
     * Sunset-specific package config (from `config/sunset.php`). Kept so
     * future work (DLX scaffolding, rate limits) can read transport options
     * without re-reading the container.
     */
    private array $packageConfig;

    public function __construct(QueueConfig $config, array $packageConfig = [])
    {
        parent::__construct($config);
        $this->packageConfig = $packageConfig;
    }

    public function push($job, $data = '', $queue = null)
    {
        $this->lastPushed = $job;
        return parent::push($job, $data, $queue);
    }

    public function pushRaw($payload, $queue = null, array $options = []): int|string|null
    {
        $prepared = (new JobPayload($payload))->prepare($this->lastPushed);
        $queueName = $this->getQueue($queue);
        $connection = $this->getConnectionName();

        event(new JobQueueing($connection, $queueName, $prepared));

        $result = parent::pushRaw($prepared->value, $queue, $options);

        event(new JobQueued($connection, $queueName, $prepared));

        // Return what the parent returned (vendor returns int|string|null —
        // the AMQP correlation id). Don't substitute the JobPayload id; the
        // worker side identifies jobs via the AMQP message correlation id.
        return $result;
    }

    public function later($delay, $job, $data = '', $queue = null): mixed
    {
        $this->lastPushed = $job;

        $queueName = $this->getQueue($queue);
        $payload = $this->createPayload($job, $queueName, $data);

        $availableAt = $this->availableAtFromDelay($delay);

        /** @var DelayedJobStore $store */
        $store = $this->container->make(DelayedJobStore::class);
        $store->buffer($queueName, $payload, (float) $availableAt);

        // Mirror Laravel's Queue contract: later() returns an identifier.
        // The reaper will publish via pushRaw() when the ETA elapses, at
        // which point the AMQP correlation id is assigned. Until then a
        // synthetic id keeps dispatch sites that inspect the return value
        // (e.g. dispatch()->onQueue()->afterResponse() chains) happy.
        return Str::random(40);
    }

    public function pop($queue = null)
    {
        $job = parent::pop($queue);

        if ($job instanceof RabbitMQJob) {
            $queueName = $this->getQueue($queue);
            $payload = new JobPayload($job->getRawBody());
            event(new JobReserved($this->getConnectionName(), $queueName, $payload));
        }

        return $job;
    }

    private function availableAtFromDelay(int|DateInterval|DateTimeInterface $delay): int
    {
        if ($delay instanceof DateTimeInterface) {
            return $delay->getTimestamp();
        }
        if ($delay instanceof DateInterval) {
            return (new \DateTime())->add($delay)->getTimestamp();
        }
        return time() + $delay;
    }
}
