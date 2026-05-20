<?php

namespace Admnio\Sunset\Contracts;

use Admnio\Sunset\Activity\ActivityEvent;

/**
 * Read API for the dashboard's activity stream.
 *
 * Implementations expose three windowed views over the bounded replay buffer
 * that the recorder writes to. The write side (recording events) is an
 * implementation detail and is not part of this contract.
 *
 * All reads MUST fail soft: if the backing store is unavailable, return an
 * empty array rather than throwing. The activity stream is observability, not
 * load-bearing, and the dashboard must remain responsive when Redis is down.
 */
interface ActivityRepository
{
    /**
     * Most recent events, newest first.
     *
     * Useful for the initial page render (Inertia props on /sunset/activity).
     *
     * @param  int  $limit  Maximum number of events to return.
     * @return list<ActivityEvent>  Descending by id.
     */
    public function recent(int $limit = 200): array;

    /**
     * Events strictly after $afterId, oldest first.
     *
     * Strict inequality: an event with id === $afterId is NOT returned. This
     * is the cursor shape the SSE stream uses to forward "everything since
     * the client's last_event_id" without re-emitting the cursor event.
     *
     * @param  int  $afterId  Cursor id; only events with id > $afterId are returned.
     * @param  int  $limit    Maximum number of events to return.
     * @return list<ActivityEvent>  Ascending by id.
     */
    public function since(int $afterId, int $limit = 1000): array;

    /**
     * Events strictly before $beforeId, newest first.
     *
     * Strict inequality: an event with id === $beforeId is NOT returned. This
     * powers the dashboard's "load older" pagination — pass the oldest id
     * currently visible to fetch the next page going backwards.
     *
     * @param  int  $beforeId  Cursor id; only events with id < $beforeId are returned.
     * @param  int  $limit     Maximum number of events to return.
     * @return list<ActivityEvent>  Descending by id.
     */
    public function before(int $beforeId, int $limit = 200): array;
}
