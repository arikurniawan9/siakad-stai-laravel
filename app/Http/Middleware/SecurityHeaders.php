<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());

        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $viteOrigins = "http://localhost:5173 http://127.0.0.1:5173 http://[::1]:5173";
        $development = app()->environment(['local', 'testing']);
        $scriptSources = $development ? "'self' 'unsafe-inline' {$viteOrigins}" : "'self'";
        $styleSources = $development ? "'self' 'unsafe-inline' {$viteOrigins}" : "'self' 'unsafe-inline'";
        $connectSources = $development ? "'self' {$viteOrigins} ws://localhost:5173 ws://127.0.0.1:5173 ws://[::1]:5173" : "'self'";

        return "default-src 'self'; script-src {$scriptSources}; style-src {$styleSources}; img-src 'self' data: blob:; font-src 'self' data:; connect-src {$connectSources}; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";
    }
}
