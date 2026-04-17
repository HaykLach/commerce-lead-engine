<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Internal\StoreDomainMetricRequest;
use App\Http\Resources\Api\Internal\DomainMetricResource;
use App\Services\InternalApi\DomainMetricIngestionService;
use Illuminate\Http\JsonResponse;

class DomainMetricController extends Controller
{
    public function __construct(
        private readonly DomainMetricIngestionService $domainMetricIngestionService,
    ) {
    }

    public function store(StoreDomainMetricRequest $request): JsonResponse
    {
        $domainMetric = $this->domainMetricIngestionService->store($request->validated());

        return (new DomainMetricResource($domainMetric))
            ->response()
            ->setStatusCode(201);
    }
}
