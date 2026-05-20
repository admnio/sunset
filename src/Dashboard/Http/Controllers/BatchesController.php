<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Illuminate\Bus\BatchRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;
use Throwable;

final class BatchesController extends Controller
{
    public function show(Request $request): InertiaResponse|JsonResponse
    {
        $batches    = [];
        $configured = true;

        // Batches are an optional Laravel feature - the BatchRepository
        // binding only exists when bus/batches are configured. Resolve it
        // defensively so the dashboard page still renders an empty list
        // (and a configuration hint) on installations without batches.
        try {
            $repo    = app(BatchRepository::class);
            $batches = $repo->get(null, 50);
        } catch (Throwable) {
            $configured = false;
        }

        return $this->inertiaOrJson($request, 'Sunset/Batches', [
            'batches'    => $batches,
            'configured' => $configured,
        ]);
    }
}
