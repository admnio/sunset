<?php

namespace Admnio\Sunset\Adapters\Horizon;

use Admnio\Sunset\Contracts\SupervisorRepository as SunsetSupervisorRepo;
use Laravel\Horizon\Contracts\SupervisorRepository as HorizonSupervisorRepository;
use Laravel\Horizon\Supervisor as HorizonSupervisor;
use RuntimeException;

class HorizonSupervisorRepositoryAdapter implements HorizonSupervisorRepository
{
    public function __construct(
        private SunsetSupervisorRepo $repo,
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

    public function longestActiveTimeout()
    {
        return $this->repo->longestActiveTimeout();
    }

    /**
     * Not supported via this adapter.
     *
     * Horizon's contract takes a Laravel\Horizon\Supervisor, but our repo expects
     * Admnio\Sunset\Supervisor\Supervisor. Sunset's supervisor calls our repo
     * directly so this adapter path is never reached at runtime.
     *
     * @throws \RuntimeException
     */
    public function update(HorizonSupervisor $supervisor)
    {
        throw new RuntimeException(
            'Adapter does not support Horizon\'s Supervisor type — Sunset\'s supervisor uses our repo directly.'
        );
    }

    public function forget($names)
    {
        $this->repo->forget($names);
    }

    public function flushExpired()
    {
        $this->repo->flushExpired();
    }
}
