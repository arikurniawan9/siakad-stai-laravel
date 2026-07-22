<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => config('siakad.institution'),
                'timezone' => config('siakad.timezone'),
            ],
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'username' => $request->user()->username,
                    'email' => $request->user()->email,
                    'activeRole' => $request->user()->active_role ?? $request->user()->getRoleNames()->first(),
                    'roles' => $request->user()->getRoleNames()->values(),
                ] : null,
            ],
            'navigation' => fn () => app(\App\Services\MenuService::class)->forUser($request->user()),
            'notifications' => fn () => $request->user() ? [
                'unreadCount' => $request->user()->systemNotifications()->whereNull('read_at')->count(),
                'recent' => $request->user()->systemNotifications()->latest()->limit(5)->get(['id', 'type', 'title', 'message', 'link', 'read_at', 'created_at']),
            ] : ['unreadCount' => 0, 'recent' => []],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
