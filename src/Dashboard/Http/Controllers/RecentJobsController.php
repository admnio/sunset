<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\JobRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

final class RecentJobsController extends Controller
{
    public function show(Request $request, JobRepository $jobs): InertiaResponse|JsonResponse
    {
        return $this->inertiaOrJson($request, 'Sunset/Recent', [
            'jobs'  => $jobs->getRecent()->all(),
            'total' => $jobs->totalRecent(),
        ]);
    }
}
