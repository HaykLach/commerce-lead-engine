<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Internal\StorePageClassificationRequest;
use App\Http\Resources\Api\Internal\PageClassificationResource;
use App\Services\InternalApi\PageClassificationIngestionService;
use Illuminate\Http\JsonResponse;

class PageClassificationController extends Controller
{
    public function __construct(
        private readonly PageClassificationIngestionService $pageClassificationIngestionService,
    ) {
    }

    public function store(StorePageClassificationRequest $request): JsonResponse
    {
        $pageClassification = $this->pageClassificationIngestionService->store($request->validated());

        return (new PageClassificationResource($pageClassification))
            ->response()
            ->setStatusCode(201);
    }
}
