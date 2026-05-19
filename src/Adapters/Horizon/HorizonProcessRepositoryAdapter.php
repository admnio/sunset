<?php

namespace Admnio\Sunset\Adapters\Horizon;

use Admnio\Sunset\Contracts\ProcessRepository as SunsetProcessRepo;
use Laravel\Horizon\Contracts\ProcessRepository as HorizonProcessRepository;

class HorizonProcessRepositoryAdapter implements HorizonProcessRepository
{
    public function __construct(
        private SunsetProcessRepo $repo,
    ) {
    }

    public function allOrphans($master)
    {
        return $this->repo->allOrphans($master);
    }

    public function orphaned($master, array $processIds)
    {
        return $this->repo->orphaned($master, $processIds);
    }

    public function orphanedFor($master, $seconds)
    {
        return $this->repo->orphanedFor($master, (int) $seconds);
    }

    public function forgetOrphans($master, array $processIds)
    {
        $this->repo->forgetOrphans($master, $processIds);
    }
}
