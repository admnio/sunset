<?php

namespace Admnio\Sunset\Repositories\Redis;

use Admnio\Sunset\Activity\ActivityEvent;
use Admnio\Sunset\Contracts\ActivityRepository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on Admnio\Sunset\Contracts\ActivityRepository for reads. The write
 *           side (record()) is internal to the ActivityRecorder listener.
 *
 * Storage layout (under the configured sunset: key prefix):
 *   - {prefix}:activity:next_id    string, INCR'd per recorded event;
 *                                  the resulting integer is the event's
 *                                  monotonic id (also the sorted-set score).
 *   - {prefix}:activity:stream     ZSET, score=id, member="{id}:{json}";
 *                                  capped to sunset.activity.stream_buffer_size
 *                                  via ZREMRANGEBYRANK after each insert.
 *                                  TTL 86400s (24h) as belt-and-braces against
 *                                  stuck keys when the recorder goes idle.
 *
 * Streaming source: the SSE endpoint cursor-polls this sorted set with
 * ZRANGEBYSCORE rather than subscribing to a pub/sub channel. The pub/sub
 * step from the original spec was dropped (see the design doc for the
 * rationale: phpredis/predis pub/sub semantics differ around blocking reads
 * + heartbeats, and the cursor-poll model is testable + portable).
 */
class RedisActivityRepository implements ActivityRepository
{
    /** Belt-and-braces TTL on the sorted set: 24 hours. */
    private const STREAM_TTL_SECONDS = 86400;

    private const NEXT_ID_KEY = 'activity:next_id';

    private const STREAM_KEY = 'activity:stream';

    public function __construct(private RedisFactory $redis)
    {
    }

    /**
     * Persist an ActivityEvent to the replay buffer. The input event's id is
     * ignored — the recorder uses 0 as a placeholder — and a fresh monotonic
     * id is assigned via INCR. The returned ActivityEvent has the assigned id.
     *
     * Exceptions propagate; the ActivityRecorder is the layer that decides
     * activity-write failures should be silent.
     */
    public function record(ActivityEvent $event): ActivityEvent
    {
        $conn = $this->connection();
        $cap = (int) config('sunset.activity.stream_buffer_size', 5000);

        // INCR is outside the pipeline because we need its return value to
        // build the member string for ZADD. One extra round-trip per record;
        // the cost is negligible compared to the JSON encode.
        $id = (int) $conn->incr($this->key(self::NEXT_ID_KEY));

        $assigned = new ActivityEvent(
            id: $id,
            type: $event->type,
            occurredAt: $event->occurredAt,
            payload: $event->payload,
        );

        $streamKey = $this->key(self::STREAM_KEY);
        $member = $id . ':' . $assigned->toJson();

        $conn->pipeline(function ($pipe) use ($streamKey, $id, $member, $cap) {
            $pipe->zadd($streamKey, $id, $member);
            // Cap the sorted set to the configured buffer size by removing
            // everything from rank 0 up to -(cap + 1). E.g. cap=5000 removes
            // ranks 0..-5001, leaving the newest 5000 entries.
            $pipe->zremrangebyrank($streamKey, 0, -1 * ($cap + 1));
            $pipe->expire($streamKey, self::STREAM_TTL_SECONDS);
        });

        return $assigned;
    }

    /**
     * @return list<ActivityEvent>
     */
    public function recent(int $limit = 200): array
    {
        try {
            $raw = (array) $this->connection()->zrevrange(
                $this->key(self::STREAM_KEY),
                0,
                max($limit, 1) - 1,
            );

            return $this->parseMembers($raw);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<ActivityEvent>
     */
    public function since(int $afterId, int $limit = 1000): array
    {
        try {
            // The "(" prefix makes the range strict-inequality, so the cursor
            // event itself is excluded — important for resume semantics.
            $raw = (array) $this->connection()->zrangebyscore(
                $this->key(self::STREAM_KEY),
                '(' . $afterId,
                '+inf',
                ['limit' => [0, max($limit, 1)]],
            );

            return $this->parseMembers($raw);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<ActivityEvent>
     */
    public function before(int $beforeId, int $limit = 200): array
    {
        try {
            // ZREVRANGEBYSCORE takes (max, min) — note the argument order
            // is flipped relative to ZRANGEBYSCORE.
            $raw = (array) $this->connection()->zrevrangebyscore(
                $this->key(self::STREAM_KEY),
                '(' . $beforeId,
                '-inf',
                ['limit' => [0, max($limit, 1)]],
            );

            return $this->parseMembers($raw);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Decode a list of "{id}:{json}" members into ActivityEvent instances.
     *
     * Defensive parsing: members missing the colon separator or carrying
     * undecodable JSON are silently skipped. The sweep should never see
     * malformed members in practice — record() always writes the canonical
     * shape — so logging would just add noise during a Redis layout migration.
     *
     * @param  list<string> $members
     * @return list<ActivityEvent>
     */
    private function parseMembers(array $members): array
    {
        $events = [];

        foreach ($members as $member) {
            $member = (string) $member;
            $colon = strpos($member, ':');
            if ($colon === false) {
                continue;
            }

            // Split on the FIRST colon only — the JSON body legitimately
            // contains colons inside string values, so we can't explode().
            $json = substr($member, $colon + 1);
            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                continue;
            }

            $events[] = ActivityEvent::fromArray($decoded);
        }

        return $events;
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
