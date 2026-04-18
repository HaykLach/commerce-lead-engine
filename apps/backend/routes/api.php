<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Internal\CrawlJobController;
use App\Http\Controllers\Api\Internal\DiscoveredDomainIngestionController;
use App\Http\Controllers\Api\Internal\DomainIngestionController;
use App\Http\Controllers\Api\Internal\DomainMetricController;
use App\Http\Controllers\Api\Internal\FingerprintController;
use App\Http\Controllers\Api\Internal\LeadController;
use App\Http\Controllers\Api\Internal\LeadScoreController;
use App\Http\Controllers\Api\Internal\PageClassificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::prefix('domains')->group(function (): void {
        Route::post('/', [DomainController::class, 'store']);
    });

    Route::prefix('internal')->group(function (): void {
        Route::post('/domains/upsert', [DomainIngestionController::class, 'upsert']);
        Route::post('/discovered-domains/ingest', [DiscoveredDomainIngestionController::class, 'store']);
        Route::post('/fingerprints', [FingerprintController::class, 'store']);
        Route::post('/page-classifications', [PageClassificationController::class, 'store']);
        Route::post('/domain-metrics', [DomainMetricController::class, 'store']);
        Route::post('/lead-scores', [LeadScoreController::class, 'store']);
        Route::post('/crawl-jobs', [CrawlJobController::class, 'store']);
        Route::get('/crawl-jobs/next', [CrawlJobController::class, 'next']);
        Route::post('/crawl-jobs/{crawlJob}/start', [CrawlJobController::class, 'start']);
        Route::post('/crawl-jobs/{crawlJob}/complete', [CrawlJobController::class, 'complete']);
        Route::post('/crawl-jobs/{crawlJob}/fail', [CrawlJobController::class, 'fail']);
        Route::get('/leads', [LeadController::class, 'index']);
    });
});
