<?php

declare(strict_types=1);

namespace App\Services\InternalApi;

use App\Models\LeadScore;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class LeadListingService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);

        $query = LeadScore::query()
            ->with(['domain', 'domainMetric'])
            ->latest('computed_at');

        $this->applyFilters($query, $filters);

        return $query->paginate($perPage);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['domain'] ?? null, fn (Builder $q, string $domain) => $q->whereHas(
                'domain',
                fn (Builder $domainQuery) => $domainQuery->where('normalized_domain', 'like', '%'.strtolower(trim($domain)).'%'),
            ))
            ->when($filters['grade'] ?? null, fn (Builder $q, string $grade) => $q->where('grade', $grade))
            ->when($filters['platform'] ?? null, fn (Builder $q, string $platform) => $q->whereHas(
                'domain',
                fn (Builder $domainQuery) => $domainQuery->where('platform', $platform),
            ))
            ->when($filters['country'] ?? null, fn (Builder $q, string $country) => $q->whereHas(
                'domain',
                fn (Builder $domainQuery) => $domainQuery->where('country', strtoupper($country)),
            ))
            ->when($filters['niche'] ?? null, fn (Builder $q, string $niche) => $q->whereHas(
                'domain',
                fn (Builder $domainQuery) => $domainQuery->where('niche', $niche),
            ))
            ->when($filters['business_model'] ?? null, fn (Builder $q, string $businessModel) => $q->whereHas(
                'domain',
                fn (Builder $domainQuery) => $domainQuery->where('business_model', $businessModel),
            ))
            ->when($filters['min_score'] ?? null, fn (Builder $q, float $minScore) => $q->where('opportunity_score', '>=', $minScore))
            ->when($filters['max_score'] ?? null, fn (Builder $q, float $maxScore) => $q->where('opportunity_score', '<=', $maxScore));
    }
}
