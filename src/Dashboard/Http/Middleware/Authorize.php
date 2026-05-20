<?php

namespace Admnio\Sunset\Dashboard\Http\Middleware;

use Admnio\Sunset\Manager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Authorize
{
    public function __construct(private Manager $manager)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $this->manager->check($request) ? $next($request) : abort(403);
    }
}
