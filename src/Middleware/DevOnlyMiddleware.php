<?php
// src/Middleware/DevOnlyMiddleware.php

namespace NativePHP\ErrorSync\Middleware;

use Closure;
use Illuminate\Http\Request;

class DevOnlyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $environments = config('error-sync.environments', ['local', 'development']);
        $forceEnabled = config('error-sync.force_enable', false);

        if (!$forceEnabled && !app()->environment($environments)) {
            abort(404);
        }

        return $next($request);
    }
}