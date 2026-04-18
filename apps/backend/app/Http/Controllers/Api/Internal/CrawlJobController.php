<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Enums\CrawlJobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Internal\CompleteCrawlJobRequest;
use App\Http\Requests\Api\Internal\FailCrawlJobRequest;
use App\Http\Requests\Api\Internal\StoreCrawlJobRequest;
use App\Http\Resources\Api\Internal\CrawlJobResource;
use App\Models\CrawlJob;
use App\Services\InternalApi\CrawlJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

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

    public function next(): Response|JsonResponse
    {
        $crawlJob = CrawlJob::query()
            ->where('status', CrawlJobStatus::Queued->value)
            ->where(function ($query): void {
                $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->first();

        if ($crawlJob === null) {
            return response()->noContent();
        }

        return (new CrawlJobResource($crawlJob))->response();
    }

    public function start(CrawlJob $crawlJob): JsonResponse
    {
        $crawlJob->update([
            'status' => CrawlJobStatus::Running,
            'started_at' => now(),
        ]);

        return (new CrawlJobResource($crawlJob->fresh()))->response();
    }

    public function complete(CompleteCrawlJobRequest $request, CrawlJob $crawlJob): JsonResponse
    {
        $crawlJob->update([
            'status' => CrawlJobStatus::Completed,
            'finished_at' => now(),
            'crawl_summary' => $request->validated('summary'),
        ]);

        $crawlJob->domain()->update([
            'last_crawled_at' => now(),
            'last_seen_at' => now(),
        ]);

        return (new CrawlJobResource($crawlJob->fresh()))->response();
    }

    public function fail(FailCrawlJobRequest $request, CrawlJob $crawlJob): JsonResponse
    {
        $crawlJob->update([
            'status' => CrawlJobStatus::Failed,
            'finished_at' => now(),
            'failure_reason' => $request->validated('error'),
        ]);

        return (new CrawlJobResource($crawlJob->fresh()))->response();
    }
}
