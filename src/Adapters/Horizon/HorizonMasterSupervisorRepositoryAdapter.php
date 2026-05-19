<?php

namespace Admnio\Sunset\Adapters\Horizon;

use Admnio\Sunset\Contracts\MasterSupervisorRepository as SunsetMasterRepo;
use Laravel\Horizon\Contracts\MasterSupervisorRepository as HorizonMasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor as HorizonMasterSupervisor;
use RuntimeException;

class HorizonMasterSupervisorRepositoryAdapter implements HorizonMasterSupervisorRepository
{
    public function __construct(
        private SunsetMasterRepo $repo,
    ) {
    }

    public function names()
    {
        return $this->repo->names();
    }

    public function all()
    {
        return $this->repo->all();
    }

    public function find($name)
    {
        return $this->repo->find($name);
    }

    public function get(array $names)
    {
        return $this->repo->get($names);
    }

    /**
     * Not supported via this adapter.
     *
     * Horizon's contract takes a Laravel\Horizon\MasterSupervisor, but our repo expects
     * Admnio\Sunset\Supervisor\MasterSupervisor. Sunset's supervisor calls our repo
     * directly so this adapter path is never reached at runtime.
     *
     * @throws \RuntimeException
     */
    public function update(HorizonMasterSupervisor $master)
    {
        throw new RuntimeException(
            'Adapter does not support Horizon\'s MasterSupervisor type — Sunset\'s supervisor uses our repo directly.'
        );
    }

    public function forget($name)
    {
        $this->repo->forget($name);
    }

    public function flushExpired()
    {
        $this->repo->flushExpired();
    }
}
