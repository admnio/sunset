<?php

namespace Admnio\Sunset\Events;

/**
 * Fired by Admnio\Sunset\Contracts\QueuePauseRepository::pause() after a
 * successful pause write. Carries the `(connection, queue)` pair that was
 * paused plus an optional `$actor` string identifying who triggered the
 * action ('dashboard', 'cli', or null for programmatic callers).
 *
 * Subscribe to this event when you want to forward pause signals to a side
 * channel: post into an audit log, ping Slack on emergency-stop, mirror
 * pauses into Datadog or another observability stack. See
 * Admnio\Sunset\Events\ActivityRecorded for the broader activity stream
 * (which itself surfaces QueuePaused as a `queue_paused` activity entry once
 * the ActivityEventFactory translates it).
 *
 * Idempotent semantics: the repository fires this event on every pause()
 * call, including ones that are redundant (the queue was already paused).
 * The event represents the operator's action, not the state delta — that's
 * intentional so the audit trail records what the operator actually did.
 *
 * Stability: part of the v1.x public API. Property names and types are
 * locked alongside Admnio\Sunset\Contracts\QueuePauseRepository.
 */
final readonly class QueuePaused
{
    public function __construct(
        public string $connection,
        public string $queue,
        public ?string $actor = null,
    ) {
    }
}
