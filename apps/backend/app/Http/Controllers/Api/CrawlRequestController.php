<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCrawlRequestRequest;
use App\Http\Resources\Api\Internal\CrawlJobResource;
use App\Services\Crawl\CrawlDiscoveryService;
use Illuminate\Http\JsonResponse;

class CrawlRequestController extends Controller
{
    public function __construct(
        private readonly CrawlDiscoveryService $crawlDiscoveryService,
    ) {
    }

    public function store(StoreCrawlRequestRequest $request): JsonResponse
    {
        $crawlJob = $this->crawlDiscoveryService->dispatch(
            country: strtolower((string) $request->validated('country')),
            limit: (int) ($request->validated('limit') ?? 200),
            minEcommerceScore: (float) ($request->validated('min_ecommerce_score') ?? 0.3),
            excludeExisting: (bool) ($request->validated('exclude_existing') ?? true),
        );

        return (new CrawlJobResource($crawlJob))
            ->response()
            ->setStatusCode(202);
    }
}
