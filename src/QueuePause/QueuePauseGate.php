<?php

namespace Admnio\Sunset\QueuePause;

use Admnio\Sunset\Contracts\QueuePauseRepository;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hot-path guard that the transport `pop()` methods consult before fetching a
 * job. Wraps Admnio\Sunset\Contracts\QueuePauseRepository so the transports
 * don't have to know about the failure-handling rules.
 *
 * Failure mode is fail-open: if the backing repository throws (Redis blip),
 * the gate returns false so workers keep popping. The alternative would be to
 * silently stall the entire fleet on a transient Redis issue, which is a
 * strictly worse failure than the small window where a recently-paused queue
 * keeps draining for one more pop cycle.
 *
 * Repository failures are logged at warning level. To avoid log-spam during a
 * sustained Redis outage (the gate is hit on every pop on every worker), the
 * warning is throttled to once per `warnIntervalSeconds` (default 60) using an
 * in-process timestamp. The throttle is per-instance — the service-provider
 * binds the gate as a singleton, so each worker process gets one shared
 * throttle, which is the right grain.
 *
 * The clock is injected so the throttle is deterministically testable.
 *
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the public Admnio\Sunset\Contracts\QueuePauseRepository
 *           contract instead — the gate is plumbing, not part of the published
 *           surface.
 */
class QueuePauseGate
{
    private ?float $lastWarnAt = null;

    /**
     * @param Closure $clock fn(): float — unix wall seconds, injected for tests.
     */
    public function __construct(
        private QueuePauseRepository $repository,
        private LoggerInterface $logger,
        private Closure $clock,
        private int $warnIntervalSeconds = 60,
    ) {
    }

    public function isPaused(string $connection, string $queue): bool
    {
        try {
            return $this->repository->isPaused($connection, $queue);
        } catch (Throwable $e) {
            $this->maybeWarn($e);
            return false;
        }
    }

    private function maybeWarn(Throwable $e): void
    {
        $now = ($this->clock)();

        if ($this->lastWarnAt !== null && ($now - $this->lastWarnAt) < $this->warnIntervalSeconds) {
            return;
        }

        $this->logger->warning(
            'Sunset: QueuePauseGate could not reach the pause repository; failing open.',
            ['exception' => $e],
        );

        $this->lastWarnAt = $now;
    }
}
