<?php

use Illuminate\Support\Facades\Route;
use Oluokunkabiru\ApiCallLogger\Http\Controllers\ApiCallLogController;
use Oluokunkabiru\ApiCallLogger\Http\Middleware\Authorize;

Route::middleware(array_merge((array) config('api-call-logger.dashboard.middleware', ['web']), [Authorize::class]))
    ->prefix(config('api-call-logger.dashboard.route_prefix', 'api-call-logs'))
    ->name('api-call-logger.')
    ->group(function () {
        Route::get('/', [ApiCallLogController::class, 'index'])->name('index');
        Route::get('/{log}', [ApiCallLogController::class, 'show'])->whereNumber('log')->name('show');
        Route::post('/{log}/retry', [ApiCallLogController::class, 'retry'])->whereNumber('log')->name('retry');
    });
