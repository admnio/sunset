<?php

namespace Admnio\Sunset\Transports\Database;

use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Events\JobReserved;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\QueuePause\QueuePauseGate;
use Admnio\Sunset\RateLimiting\RateLimitGate;
use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class DatabaseQueue extends LaravelDatabaseQueue
{
    private mixed $lastPushed = null;

    public function push($job, $data = '', $queue = null)
    {
        $this->lastPushed = $job;

        // Mirror the parent implementation but route the insert through
        // pushPrepared() so the payload is Sunset-tagged and the queue events
        // fire. (Unlike RedisQueue, the database driver's push() does not
        // funnel through pushRaw(), so intercepting there is not enough.)
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            fn ($payload, $queue) => $this->pushPrepared($queue, $payload, 0)
        );
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        $this->lastPushed = $job;

        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data, $delay),
            $queue,
            $delay,
            fn ($payload, $queue, $delay) => $this->pushPrepared($queue, $payload, $delay)
        );
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        return $this->pushPrepared($queue, $payload, 0);
    }

    /**
     * Prepare the payload, emit the queueing events, and insert the job.
     *
     * Deliberately separate from the parent's pushToDatabase() so that the
     * release()/retry path (which also calls pushToDatabase) does NOT re-tag
     * the payload or fire a spurious JobQueued event.
     */
    protected function pushPrepared($queue, $payload, $delay = 0)
    {
        $prepared = (new JobPayload($payload))->prepare($this->lastPushed);
        $queueName = $this->getQueue($queue);
        $connection = $this->getConnectionName();

        event(new JobQueueing($connection, $queueName, $prepared));

        $id = $this->pushToDatabase($queue, $prepared->value, $delay);

        event(new JobQueued($connection, $queueName, $prepared));

        return $id;
    }

    public function pop($queue = null)
    {
        $queueName = $this->getQueue($queue);

        // v1.3.0 queue-pause gate (fails open on storage errors), mirroring
        // RedisQueue::pop so pausing works uniformly across transports.
        if ($this->container->make(QueuePauseGate::class)
                ->isPaused((string) $this->getConnectionName(), $queueName)) {
            return null;
        }

        $job = parent::pop($queue);

        if ($job !== null) {
            $payload = new JobPayload($job->getRawBody());
            event(new JobReserved($this->getConnectionName(), $queueName, $payload));

            $gate = $this->container->make(RateLimitGate::class);
            $decoded = json_decode($job->getRawBody(), true) ?: [];
            $decoded['connection'] = $this->getConnectionName();
            $tags = is_array($decoded['tags'] ?? null) ? $decoded['tags'] : [];

            $decision = $gate->admit($job, $decoded, $queueName, $tags);
            if (! $decision->admitted) {
                // Gate already invoked release()/fail()/delete() per the
                // limit's overLimit strategy. Returning null tells the worker
                // to loop without dispatching.
                return null;
            }
        }

        return $job;
    }
}
