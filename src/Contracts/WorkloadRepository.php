<?php

namespace Admnio\Sunset\Contracts;

interface WorkloadRepository
{
    /**
     * Return per-queue workload rows.
     *
     * @return array<int, array{
     *   name: string,
     *   length: int,
     *   processes: int,
     *   wait: int,
     *   split_queues: null|array<int, mixed>
     * }>
     */
    public function get(): array;
}
