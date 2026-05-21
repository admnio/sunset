<?php

namespace Admnio\Sunset\Repositories\Redis;

use Admnio\Sunset\Contracts\QueuePauseRepository;
use Admnio\Sunset\Events\QueuePaused;
use Admnio\Sunset\Events\QueueResumed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on Admnio\Sunset\Contracts\QueuePauseRepository instead.
 *
 * Storage layout (under the configured sunset: key prefix):
 *   - {prefix}:queues:paused   SET of "{connection}:{queue}" strings.
 *                              No TTL — pauses persist until explicitly
 *                              resumed. The set is uncapped; SISMEMBER stays
 *                              O(1) regardless of population.
 *
 * Why a SET and not a hash: the hot path (pop() gate check) is SISMEMBER,
 * which is exactly what a SET gives us. SMEMBERS for the dashboard render is
 * acceptable at expected cardinalities (operators won't pause thousands of
 * queues).
 *
 * Event semantics: pause() and resume() always dispatch their corresponding
 * QueuePaused / QueueResumed event, even when the SADD / SREM was a no-op.
 * The event represents the operator's action, not the underlying state
 * transition — see the QueuePauseRepository contract docblock.
 */
class RedisQueuePauseRepository implements QueuePauseRepository
{
    private const PAUSED_SET_KEY = 'queues:paused';

    public function __construct(
        private RedisFactory $redis,
        private Dispatcher $events,
    ) {
    }

    public function pause(string $connection, string $queue, ?string $actor = null): void
    {
        // Re-throws on Redis failure: the caller (dashboard controller / CLI
        // command) needs to surface the error so the operator can retry.
        $this->connection()->sadd(
            $this->key(self::PAUSED_SET_KEY),
            $this->member($connection, $queue),
        );

        $this->events->dispatch(new QueuePaused($connection, $queue, $actor));
    }

    public function resume(string $connection, string $queue, ?string $actor = null): void
    {
        $this->connection()->srem(
            $this->key(self::PAUSED_SET_KEY),
            $this->member($connection, $queue),
        );

        $this->events->dispatch(new QueueResumed($connection, $queue, $actor));
    }

    public function isPaused(string $connection, string $queue): bool
    {
        // Fail-soft read: a Redis outage must not stop the worker fleet — see
        // the contract docblock. The QueuePauseGate (v1.3.0 Task 2) layers an
        // additional throttled warning log on top of this; here we just return
        // false so the worker keeps popping.
        try {
            return (bool) $this->connection()->sismember(
                $this->key(self::PAUSED_SET_KEY),
                $this->member($connection, $queue),
            );
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{connection: string, queue: string}>
     */
    public function all(): array
    {
        try {
            $members = (array) $this->connection()->smembers($this->key(self::PAUSED_SET_KEY));
        } catch (Throwable) {
            return [];
        }

        $out = [];

        foreach ($members as $member) {
            $member = (string) $member;

            // Split on the FIRST colon only: queue names may legitimately
            // contain colons (e.g. SQS allows ".fifo" suffixes, custom apps
            // sometimes namespace queues like "tenant:foo"). Connection names
            // come from queue.connections.{name} in config/queue.php and
            // don't contain colons.
            $colon = strpos($member, ':');
            if ($colon === false) {
                continue;
            }

            $out[] = [
                'connection' => substr($member, 0, $colon),
                'queue' => substr($member, $colon + 1),
            ];
        }

        return $out;
    }

    private function member(string $connection, string $queue): string
    {
        return $connection . ':' . $queue;
    }

    private function key(string $name): string
    {
        return config('sunset.key_prefix', 'sunset') . ':' . $name;
    }

    private function connection()
    {
        return $this->redis->connection(config('sunset.redis_connection', 'default'));
    }
}
