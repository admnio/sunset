<?php

namespace Admnio\Sunset\Contracts;

/**
 * Read + write API for the per-queue pause/resume control surface introduced
 * in v1.3.0.
 *
 * "Pausing a queue" is a soft signal: workers stop popping new jobs from the
 * named `(connection, queue)` pair on their next pop cycle, but in-flight jobs
 * run to completion and producers can still enqueue. See the v1.3.0 design doc
 * for the full failure-semantics matrix.
 *
 * Unlike Admnio\Sunset\Contracts\ActivityRepository and WorkerMetricsRepository
 * (which expose reads only — the write side is an internal recorder concern),
 * this contract is read+write. Pause and resume are explicit operator actions
 * that programmatic consumers may legitimately script via the contract — e.g.
 * a deploy script that pauses a queue before a downstream maintenance window.
 *
 * Implementations MUST fire the corresponding Admnio\Sunset\Events\QueuePaused
 * / QueueResumed event after each successful write. The event is the dashboard
 * activity-stream's hook point and consumers may subscribe to it directly to
 * forward pause/resume signals into their own observability stack.
 *
 * Reads MUST fail soft (return false or empty array) when the backing store is
 * unavailable so the dashboard renders rather than 500s. Writes MUST re-throw
 * so the operator sees the failure and can retry.
 */
interface QueuePauseRepository
{
    /**
     * Mark the `(connection, queue)` pair as paused and dispatch QueuePaused.
     *
     * Idempotent at the storage layer (a second pause is a no-op there), but
     * the dispatched event represents the operator's action — it fires on
     * every call, including the redundant ones, so the activity log faithfully
     * records what the operator did.
     *
     * @param string      $connection Laravel queue connection name (e.g. 'sqs', 'redis').
     * @param string      $queue      Queue name as it appears in the worker invocation.
     * @param string|null $actor      Free-form string tagging the source of the pause
     *                                (e.g. 'dashboard', 'cli'); forwarded into the
     *                                dispatched event. Null for programmatic callers
     *                                that don't want to claim a source.
     */
    public function pause(string $connection, string $queue, ?string $actor = null): void;

    /**
     * Clear the pause on the `(connection, queue)` pair and dispatch QueueResumed.
     *
     * Same idempotence semantics as pause(): the storage write is a no-op when
     * the queue isn't currently paused, but the event fires regardless.
     *
     * @param string      $connection Laravel queue connection name.
     * @param string      $queue      Queue name.
     * @param string|null $actor      Free-form string tagging the source of the resume
     *                                (e.g. 'dashboard', 'cli'); forwarded into the
     *                                dispatched event.
     */
    public function resume(string $connection, string $queue, ?string $actor = null): void;

    /**
     * Is the given `(connection, queue)` pair currently paused?
     *
     * Called on every worker pop() via QueuePauseGate, so implementations
     * should keep this O(1) — the Redis implementation uses SISMEMBER.
     *
     * Reads MUST fail soft: return false when the backing store is unavailable,
     * never throw. A storage outage must not silently stop the entire fleet.
     */
    public function isPaused(string $connection, string $queue): bool;

    /**
     * Every currently-paused `(connection, queue)` pair.
     *
     * Used by the dashboard's workload page to render the paused indicator and
     * pause/resume buttons. Implementations MUST fail soft (return []) when
     * the backing store is unavailable.
     *
     * @return list<array{connection: string, queue: string}>
     */
    public function all(): array;
}
