<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Services\Domain\DomainEnrichmentPipeline;
use Illuminate\Contracts\Container\BindingResolutionException;
use Throwable;

class ProcessPendingDomainEnrichmentJob extends ProcessDomainCrawlJob
{
    /**
     * @throws BindingResolutionException
     */
    public function handle(): void
    {
        /** @var DomainEnrichmentPipeline $pipeline */
        $pipeline = app()->make(DomainEnrichmentPipeline::class);

        $domain = Domain::query()->where('normalized_domain', $this->domain)->first();

        if ($domain === null) {
            return;
        }

        try {
            $pipeline->run($domain);

            $domain->forceFill([
                'status' => DomainStatus::Processed,
            ])->save();
        } catch (Throwable $exception) {
            $metadata = is_array($domain->metadata) ? $domain->metadata : [];
            $metadata['processing_error'] = $exception->getMessage();

            $attributes = [
                'status' => DomainStatus::Failed,
                'metadata' => $metadata,
            ];

            if (array_key_exists('error_message', $domain->getAttributes())) {
                $attributes['error_message'] = $exception->getMessage();
            }

            $domain->forceFill($attributes)->save();

            throw $exception;
        }
    }
}
