<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyBsiCallbackSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('bsi.callback_secret');
        $signature = (string) $request->header('X-SIAKAD-Signature');
        $expected = $secret ? hash_hmac('sha256', $request->getContent(), $secret) : '';

        if (! $secret || ! $signature || ! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Callback tidak terautentikasi.'], 401);
        }

        return $next($request);
    }
}
