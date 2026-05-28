<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Support\QueueControl;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Deploy-safe drain: pause every configured queue, then block until all
 * in-flight (reserved) jobs across the fleet have finished. Pair with
 * `sunset:resume` after the deploy if the workers survive.
 *
 * Built for rolling deploys where worker containers may be torn down: pausing
 * stops new work (Redis-backed, so it reaches workers in other containers),
 * and waiting on the reserved-jobs index guarantees nothing is mid-execution
 * when the containers go away.
 *
 * Exit code is non-zero if `--timeout` elapses with jobs still in flight, so a
 * deploy script can decide whether to proceed or abort.
 *
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Depend on the command
 *           name `sunset:pause-and-wait`, not this class.
 */
#[AsCommand(name: 'sunset:pause-and-wait')]
class SunsetPauseAndWaitCommand extends Command
{
    protected $signature = 'sunset:pause-and-wait
        {--timeout=0 : Max seconds to wait for jobs to drain (0 = wait indefinitely)}
        {--interval=1 : Seconds between in-flight checks}';

    protected $description = 'Pause all queues, then block until in-flight jobs finish. For safe deploys.';

    public function __construct(
        private readonly QueueControl $queues,
        private readonly JobRepository $jobs,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $timeout  = max(0, (int) $this->option('timeout'));
        $interval = max(1, (int) $this->option('interval'));

        $pairs = $this->queues->pauseAll('cli');
        if ($pairs === []) {
            $this->components->warn('No configured queues found to pause (check sunset.supervisors).');
        } else {
            $this->components->info(sprintf('Paused %d queue(s). Waiting for in-flight jobs to finish…', count($pairs)));
        }

        $start = time();
        while (true) {
            $inFlight = $this->jobs->countReserved();

            if ($inFlight === 0) {
                $this->components->info('All jobs drained — safe to proceed.');

                return self::SUCCESS;
            }

            $elapsed = time() - $start;
            if ($timeout > 0 && $elapsed >= $timeout) {
                $this->components->error(sprintf(
                    'Timed out after %ds with %d job(s) still in flight. Queues remain paused.',
                    $elapsed,
                    $inFlight,
                ));

                return self::FAILURE;
            }

            $this->components->twoColumnDetail(
                sprintf('  in flight: %d', $inFlight),
                sprintf('elapsed %ds', $elapsed),
            );

            sleep($interval);
        }
    }
}
