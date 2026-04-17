<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Internal\StoreFingerprintRequest;
use App\Http\Resources\Api\Internal\FingerprintResource;
use App\Services\InternalApi\FingerprintIngestionService;
use Illuminate\Http\JsonResponse;

class FingerprintController extends Controller
{
    public function __construct(
        private readonly FingerprintIngestionService $fingerprintIngestionService,
    ) {
    }

    public function store(StoreFingerprintRequest $request): JsonResponse
    {
        $fingerprint = $this->fingerprintIngestionService->store($request->validated());

        return (new FingerprintResource($fingerprint))
            ->response()
            ->setStatusCode(201);
    }
}
