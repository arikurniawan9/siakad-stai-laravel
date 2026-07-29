<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class UseFileSessionForSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            config('session.driver') === 'database'
            && ($request->is('superadmin') || $request->is('superadmin/*'))
        ) {
            config(['session.driver' => 'file']);
        }

        return $next($request);
    }
}
