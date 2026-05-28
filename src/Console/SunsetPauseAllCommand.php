<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\Support\QueueControl;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Pause every configured queue across the fleet and return immediately.
 *
 * Uses the Redis-backed per-queue pause state (shared across hosts/containers),
 * so it works from a deploy step running in a different container than the
 * workers — unlike the OS-signal master pause (`sunset:pause-master`). Workers
 * stop popping new jobs on their next loop iteration; in-flight jobs keep
 * running and producers can still enqueue.
 *
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Depend on the command
 *           name `sunset:pause`, not this class.
 */
#[AsCommand(name: 'sunset:pause')]
class SunsetPauseAllCommand extends Command
{
    protected $signature = 'sunset:pause';

    protected $description = 'Pause all configured queues (fleet-wide, across containers). Returns immediately.';

    public function __construct(private readonly QueueControl $queues)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $pairs = $this->queues->pauseAll('cli');

        if ($pairs === []) {
            $this->components->warn('No configured queues found to pause (check sunset.supervisors).');

            return self::SUCCESS;
        }

        foreach ($pairs as $pair) {
            $this->components->twoColumnDetail("{$pair['connection']}:{$pair['queue']}", '<fg=yellow>paused</>');
        }

        $this->components->info(sprintf('Paused %d queue(s). Workers stop popping on their next loop.', count($pairs)));

        return self::SUCCESS;
    }
}
