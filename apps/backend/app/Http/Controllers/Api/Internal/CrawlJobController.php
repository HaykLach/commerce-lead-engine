<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Internal\StoreCrawlJobRequest;
use App\Http\Resources\Api\Internal\CrawlJobResource;
use App\Services\InternalApi\CrawlJobService;
use Illuminate\Http\JsonResponse;

class CrawlJobController extends Controller
{
    public function __construct(
        private readonly CrawlJobService $crawlJobService,
    ) {
    }

    public function store(StoreCrawlJobRequest $request): JsonResponse
    {
        $crawlJob = $this->crawlJobService->create($request->validated());

        return (new CrawlJobResource($crawlJob))
            ->response()
            ->setStatusCode(201);
    }
}
