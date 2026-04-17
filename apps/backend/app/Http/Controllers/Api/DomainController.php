<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DTOs\DomainDiscoveryRequestData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDomainRequest;
use App\Http\Resources\Api\DomainResource;
use App\Services\Domain\DomainDiscoveryService;
use Illuminate\Http\JsonResponse;

class DomainController extends Controller
{
    public function __construct(
        private readonly DomainDiscoveryService $domainDiscoveryService,
    ) {
    }

    public function store(StoreDomainRequest $request): JsonResponse
    {
        $payload = DomainDiscoveryRequestData::fromArray($request->validated());

        $domain = $this->domainDiscoveryService->queueDomain($payload);

        return (new DomainResource($domain))
            ->response()
            ->setStatusCode(202);
    }
}
