<?php

namespace Admnio\Sunset\Events;

class UnableToLaunchProcess
{
    /**
     * The worker process instance.
     *
     * @var object
     */
    public $process;

    /**
     * Create a new event instance.
     *
     * @param  object  $process
     * @return void
     */
    public function __construct(object $process)
    {
        $this->process = $process;
    }
}
