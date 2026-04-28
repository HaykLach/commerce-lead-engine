<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Internal\IngestDiscoveredDomainRequest;
use App\Http\Resources\Api\DomainResource;
use App\Http\Resources\Api\Internal\CrawlJobResource;
use App\Services\InternalApi\DiscoveredDomainIngestionService;
use Illuminate\Http\JsonResponse;

class DiscoveredDomainIngestionController extends Controller
{
    public function __construct(
        private readonly DiscoveredDomainIngestionService $discoveredDomainIngestionService,
    ) {
    }

    public function store(IngestDiscoveredDomainRequest $request): JsonResponse
    {
        $result = $this->discoveredDomainIngestionService->ingest($request->all());

        return response()->json([
            'status' => 'ok',
            'data' => [
                'id' => $result['domain']->id,
                'domain' => $result['domain']->domain,
                'normalized_domain' => $result['domain']->normalized_domain,
                'created' => $result['created'],
                'domain_details' => (new DomainResource($result['domain']))->resolve(),
                'domain_source' => [
                    'id' => $result['domain_source']->id,
                    'domain_id' => $result['domain_source']->domain_id,
                    'source_type' => $result['domain_source']->source_type?->value,
                    'source_name' => $result['domain_source']->source_name,
                    'source_reference' => $result['domain_source']->source_reference,
                    'context' => $result['domain_source']->context,
                ],
                'follow_up_jobs' => [
                    'homepage_fetch' => isset($result['follow_up_jobs']['homepage_fetch'])
                        ? (new CrawlJobResource($result['follow_up_jobs']['homepage_fetch']))->resolve()
                        : null,
                    'page_classification' => isset($result['follow_up_jobs']['page_classification'])
                        ? (new CrawlJobResource($result['follow_up_jobs']['page_classification']))->resolve()
                        : null,
                ],
            ],
        ], 201);
    }
}
