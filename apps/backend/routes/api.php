<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::prefix('domains')->group(function (): void {
        Route::post('/', [DomainController::class, 'store']);
    });
});
