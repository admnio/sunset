<?php

namespace Admnio\Sunset\Adapters\Horizon;

use Admnio\Sunset\Contracts\SupervisorCommandQueue as SunsetCommandQueue;
use Laravel\Horizon\Contracts\HorizonCommandQueue;

class HorizonSupervisorCommandQueueAdapter implements HorizonCommandQueue
{
    public function __construct(
        private SunsetCommandQueue $queue,
    ) {
    }

    public function push($name, $command, array $options = [])
    {
        $this->queue->push($name, $command, $options);
    }

    public function pending($name)
    {
        return $this->queue->pending($name);
    }

    public function flush($name)
    {
        $this->queue->flush($name);
    }
}
