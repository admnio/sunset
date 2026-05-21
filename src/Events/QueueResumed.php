<?php

namespace Admnio\Sunset\Events;

/**
 * Fired by Admnio\Sunset\Contracts\QueuePauseRepository::resume() after a
 * successful resume write. Carries the `(connection, queue)` pair that was
 * resumed plus an optional `$actor` string identifying who triggered the
 * action ('dashboard', 'cli', or null for programmatic callers).
 *
 * Subscribe to this event when you want to forward resume signals to a side
 * channel: post into an audit log, ping Slack on resume, mirror state
 * transitions into Datadog or another observability stack. See
 * Admnio\Sunset\Events\ActivityRecorded for the broader activity stream
 * (which itself surfaces QueueResumed as a `queue_resumed` activity entry
 * once the ActivityEventFactory translates it).
 *
 * Idempotent semantics: the repository fires this event on every resume()
 * call, including ones that are redundant (the queue wasn't paused). The
 * event represents the operator's action, not the state delta.
 *
 * Stability: part of the v1.x public API. Property names and types are
 * locked alongside Admnio\Sunset\Contracts\QueuePauseRepository.
 */
final readonly class QueueResumed
{
    public function __construct(
        public string $connection,
        public string $queue,
        public ?string $actor = null,
    ) {
    }
}
