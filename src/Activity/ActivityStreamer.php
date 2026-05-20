<?php

namespace Admnio\Sunset\Activity;

use Admnio\Sunset\Contracts\ActivityRepository;
use Closure;

/**
 * Server-Sent Events generator for the dashboard's activity stream.
 *
 * The streamer drives a single SSE response: it cursor-polls the activity
 * repository every `pollIntervalSeconds`, forwards each new event as an SSE
 * frame, and emits a `:heartbeat\n\n` comment whenever the heartbeat interval
 * elapses without traffic so proxies and firewalls don't close the idle
 * connection. After `maxConnectionSeconds` of wall time the loop exits and
 * the EventSource client reconnects on its own (sending Last-Event-ID, which
 * the controller forwards back as the next stream()'s $lastEventId).
 *
 * All I/O (clock, sleep, write-to-output) is injected as closures so this
 * class is fully unit-testable without touching real time or PHP's output
 * buffer. The clock returns float unix seconds (microtime(true) in prod);
 * sleep blocks for the given seconds (sleep() in prod); emit writes one SSE
 * frame to the client (echo + ob_flush + flush in prod).
 *
 * Frame format (per the SSE spec):
 *     id: {monotonic id}\nevent: {snake_case type}\ndata: {json}\n\n
 *
 * The trailing blank line is required by the spec — it terminates the event.
 * The `id:` field lets the browser's EventSource send Last-Event-ID on
 * reconnect; `event:` lets clients use addEventListener('job_failed', ...)
 * for per-type handlers without dispatching off the JSON.
 *
 * Initial-state subtlety: when $lastEventId is null (first connection, the
 * client has no prior cursor), the streamer seeds its cursor at PHP_INT_MAX
 * rather than 0. since(0,100) would replay the OLDEST 100 events in the
 * buffer — which is the opposite of what a "live" stream wants. The dashboard
 * is expected to render its initial event list from the Inertia props
 * (controller calls $repo->recent(200)) and then connect EventSource with
 * the highest id from that render as Last-Event-ID, so $lastEventId is
 * usually non-null in practice. PHP_INT_MAX is the safe fallback for the
 * edge case where the client opens the stream without a prior page render
 * (manual EventSource construction, test harnesses, etc.): no flood of
 * history, and the next reconnect catches up via Last-Event-ID.
 *
 * @internal This class is part of Sunset's internal implementation; it is
 *           wired by ActivityController::stream() and not intended for direct
 *           use by consumers. Signatures may change between minor releases
 *           of v1.x.
 */
class ActivityStreamer
{
    /**
     * @param ActivityRepository $repository                Cursor-polled for new events each iteration.
     * @param int                $maxConnectionSeconds      Wall-clock cap on the stream() loop. The EventSource client reconnects after we return.
     * @param int                $heartbeatIntervalSeconds  Maximum quiet period before emitting a `:heartbeat\n\n` comment.
     * @param int                $pollIntervalSeconds       Sleep duration between repository polls. The dashboard's effective freshness floor.
     * @param Closure            $clock                     fn(): float — unix wall seconds. microtime(true) in production.
     * @param Closure            $sleep                     fn(int $seconds): void — block for the given seconds. sleep() in production.
     * @param Closure            $emit                      fn(string $sseFrame): void — write one SSE frame to the client.
     */
    public function __construct(
        private readonly ActivityRepository $repository,
        private readonly int $maxConnectionSeconds,
        private readonly int $heartbeatIntervalSeconds,
        private readonly int $pollIntervalSeconds,
        private readonly Closure $clock,
        private readonly Closure $sleep,
        private readonly Closure $emit,
    ) {
    }

    /**
     * Run the SSE loop until $maxConnectionSeconds elapse, then return so the
     * controller can close the response and the client can reconnect.
     *
     * Pass the request's Last-Event-ID header (parsed to int) as $lastEventId.
     * Pass null when the request has no Last-Event-ID — the streamer will
     * forward only new events from that point forward; see the class PHPDoc
     * for the PHP_INT_MAX seeding rationale.
     */
    public function stream(?int $lastEventId): void
    {
        $start = ($this->clock)();
        $lastHeartbeat = $start;
        // since() uses strict-greater-than semantics. PHP_INT_MAX means "no
        // event id can be greater than this," so the initial poll returns
        // an empty list — exactly what we want for a fresh connect that
        // didn't replay through Last-Event-ID.
        $cursor = $lastEventId ?? PHP_INT_MAX;

        while (true) {
            $now = ($this->clock)();
            if ($now - $start >= $this->maxConnectionSeconds) {
                return;
            }

            foreach ($this->repository->since($cursor, 100) as $event) {
                ($this->emit)($this->frame($event));
                $cursor = $event->id;
                // Reset the heartbeat timer when we emit real traffic — no
                // need for a keep-alive if the connection just saw data.
                $lastHeartbeat = ($this->clock)();
            }

            $now = ($this->clock)();
            if ($now - $lastHeartbeat >= $this->heartbeatIntervalSeconds) {
                ($this->emit)(":heartbeat\n\n");
                $lastHeartbeat = $now;
            }

            ($this->sleep)($this->pollIntervalSeconds);
        }
    }

    private function frame(ActivityEvent $event): string
    {
        return sprintf(
            "id: %d\nevent: %s\ndata: %s\n\n",
            $event->id,
            $event->type,
            $event->toJson(),
        );
    }
}
