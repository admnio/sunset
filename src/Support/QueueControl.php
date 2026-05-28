<?php

namespace Admnio\Sunset\Support;

use Admnio\Sunset\Contracts\QueuePauseRepository;

/**
 * Fleet-wide pause/resume helper used by the deploy commands
 * (`sunset:pause`, `sunset:resume`, `sunset:pause-and-wait`).
 *
 * "All queues" is derived from the configured supervisors — every
 * (connection, queue) pair any supervisor is set up to consume, across every
 * environment block. Over-inclusion is harmless: pausing a (connection, queue)
 * no live worker consumes is a no-op set membership; under-inclusion is the
 * real risk (a queue left un-paused keeps being popped), so we err inclusive.
 *
 * @internal
 */
final class QueueControl
{
    public function __construct(private readonly QueuePauseRepository $pauses)
    {
    }

    /**
     * Every distinct (connection, queue) pair across all configured supervisors
     * (sunset.supervisors) plus the Horizon-compat fallback (sunset.environments).
     *
     * @return list<array{connection: string, queue: string}>
     */
    public function pairs(): array
    {
        $pairs = [];

        $blocks = array_merge(
            array_values((array) config('sunset.supervisors', [])),
            array_values((array) config('sunset.environments', [])),
        );

        foreach ($blocks as $supervisors) {
            foreach ((array) $supervisors as $supervisor) {
                if (! is_array($supervisor)) {
                    continue;
                }
                $connection = $supervisor['connection'] ?? null;
                if (! is_string($connection) || $connection === '') {
                    continue;
                }
                // `queue` may be an array (Sunset/Horizon config) or a single
                // comma-separated string.
                $queues = $supervisor['queue'] ?? [];
                $queues = is_array($queues) ? $queues : explode(',', (string) $queues);

                foreach ($queues as $queue) {
                    $queue = trim((string) $queue);
                    if ($queue === '') {
                        continue;
                    }
                    $pairs["{$connection}\0{$queue}"] = ['connection' => $connection, 'queue' => $queue];
                }
            }
        }

        return array_values($pairs);
    }

    /**
     * Pause every configured (connection, queue). Returns the paired list.
     *
     * @return list<array{connection: string, queue: string}>
     */
    public function pauseAll(?string $actor = 'cli'): array
    {
        $pairs = $this->pairs();
        foreach ($pairs as $pair) {
            $this->pauses->pause($pair['connection'], $pair['queue'], $actor);
        }
        return $pairs;
    }

    /**
     * Resume every configured (connection, queue). Returns the paired list.
     *
     * @return list<array{connection: string, queue: string}>
     */
    public function resumeAll(?string $actor = 'cli'): array
    {
        $pairs = $this->pairs();
        foreach ($pairs as $pair) {
            $this->pauses->resume($pair['connection'], $pair['queue'], $actor);
        }
        return $pairs;
    }
}
