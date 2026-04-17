<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'commerce-lead-engine-backend',
        'area' => 'web',
        'status' => 'ok',
    ]);
});

Route::prefix('admin')->group(function (): void {
    // Placeholder for admin/dashboard web routes.
});
