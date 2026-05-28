<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\Support\QueueControl;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Resume every configured queue across the fleet — the counterpart to
 * `sunset:pause` / `sunset:pause-and-wait`. Workers start popping again on
 * their next loop iteration.
 *
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Depend on the command
 *           name `sunset:resume`, not this class.
 */
#[AsCommand(name: 'sunset:resume')]
class SunsetResumeAllCommand extends Command
{
    protected $signature = 'sunset:resume';

    protected $description = 'Resume all configured queues (fleet-wide). Counterpart to sunset:pause.';

    public function __construct(private readonly QueueControl $queues)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $pairs = $this->queues->resumeAll('cli');

        if ($pairs === []) {
            $this->components->warn('No configured queues found to resume (check sunset.supervisors).');

            return self::SUCCESS;
        }

        foreach ($pairs as $pair) {
            $this->components->twoColumnDetail("{$pair['connection']}:{$pair['queue']}", '<fg=green>resumed</>');
        }

        $this->components->info(sprintf('Resumed %d queue(s).', count($pairs)));

        return self::SUCCESS;
    }
}
