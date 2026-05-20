<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\WorkloadRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

final class WorkloadController extends Controller
{
    public function show(Request $request, WorkloadRepository $workload): InertiaResponse|JsonResponse
    {
        return $this->inertiaOrJson($request, 'Sunset/Workload', [
            'queues' => $workload->get(),
        ]);
    }
}
