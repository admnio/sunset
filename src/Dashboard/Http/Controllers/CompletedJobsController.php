<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\JobRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

final class CompletedJobsController extends Controller
{
    public function show(Request $request, JobRepository $jobs): InertiaResponse|JsonResponse
    {
        return $this->inertiaOrJson($request, 'Sunset/Completed', [
            'jobs'  => $jobs->getCompleted()->all(),
            'total' => $jobs->countCompleted(),
        ]);
    }
}
