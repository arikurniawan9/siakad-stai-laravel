<?php

use App\Http\Controllers\BsiCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/bank/bsi/va/callback', BsiCallbackController::class)
    ->middleware('bsi.signature')
    ->middleware('throttle:60,1')
    ->name('api.bsi.va.callback');
