<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Internal\UpsertDiscoveredDomainRequest;
use App\Http\Resources\Api\DomainResource;
use App\Services\InternalApi\DomainIngestionService;
use Illuminate\Http\JsonResponse;

class DomainIngestionController extends Controller
{
    public function __construct(
        private readonly DomainIngestionService $domainIngestionService,
    ) {
    }

    public function upsert(UpsertDiscoveredDomainRequest $request): JsonResponse
    {
        $domain = $this->domainIngestionService->upsert($request->validated());

        return (new DomainResource($domain))
            ->response()
            ->setStatusCode(200);
    }
}
