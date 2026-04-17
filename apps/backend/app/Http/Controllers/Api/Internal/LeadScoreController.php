<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Internal\StoreLeadScoreRequest;
use App\Http\Resources\Api\Internal\LeadScoreResource;
use App\Services\InternalApi\LeadScoreIngestionService;
use Illuminate\Http\JsonResponse;

class LeadScoreController extends Controller
{
    public function __construct(
        private readonly LeadScoreIngestionService $leadScoreIngestionService,
    ) {
    }

    public function store(StoreLeadScoreRequest $request): JsonResponse
    {
        $leadScore = $this->leadScoreIngestionService->store($request->validated());

        return (new LeadScoreResource($leadScore))
            ->response()
            ->setStatusCode(201);
    }
}
