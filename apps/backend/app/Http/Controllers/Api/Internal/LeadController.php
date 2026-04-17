<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Internal\ListLeadsRequest;
use App\Http\Resources\Api\Internal\LeadResource;
use App\Services\InternalApi\LeadListingService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeadController extends Controller
{
    public function __construct(
        private readonly LeadListingService $leadListingService,
    ) {
    }

    public function index(ListLeadsRequest $request): AnonymousResourceCollection
    {
        $leads = $this->leadListingService->list($request->validated());

        return LeadResource::collection($leads);
    }
}
