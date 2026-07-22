<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('guidance:send-reminders')->hourly()->withoutOverlapping();
    })
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            App\Http\Middleware\HandleInertiaRequests::class,
            App\Http\Middleware\SecurityHeaders::class,
        ]);
        $middleware->alias([
            'bsi.signature' => App\Http\Middleware\VerifyBsiCallbackSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
