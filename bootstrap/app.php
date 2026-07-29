<?php

use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UseFileSessionForSuperAdmin;
use App\Http\Middleware\VerifyBsiCallbackSignature;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('guidance:send-reminders')->hourly()->withoutOverlapping();
        $schedule->command('finance:queue-reminders')->dailyAt('08:00')->withoutOverlapping();
        $schedule->command('finance:dispatch-notifications')->everyMinute()->withoutOverlapping();
    })
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(
            prepend: [
                UseFileSessionForSuperAdmin::class,
            ],
            append: [
                HandleInertiaRequests::class,
                SecurityHeaders::class,
            ],
        );
        $middleware->alias([
            'bsi.signature' => VerifyBsiCallbackSignature::class,
            'superadmin' => EnsureSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
