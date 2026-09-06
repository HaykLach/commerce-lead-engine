<?php

declare(strict_types=1);

namespace App\Services\PageSpeed;

use App\Services\Enrichment\AuditUrl;
use Carbon\CarbonImmutable;
use Throwable;

class PageSpeedResultParser
{
    private const METRICS = ['first-contentful-paint' => 'ms', 'largest-contentful-paint' => 'ms', 'speed-index' => 'ms', 'total-blocking-time' => 'ms', 'cumulative-layout-shift' => 'unitless'];

    public function parse(array $data, string $requestedUrl, string $domain, string $strategy): array
    {
        $lab = $data['lighthouseResult'] ?? [];
        if (! is_array($lab) || ! empty($lab['runtimeError'])) {
            throw new PageSpeedException('lighthouse_error', 'Lighthouse could not measure this page.', true);
        }
        $final = AuditUrl::normalize(is_string($lab['finalUrl'] ?? null) ? $lab['finalUrl'] : '');
        $returnedRequest = AuditUrl::normalize(is_string($lab['requestedUrl'] ?? null) ? $lab['requestedUrl'] : '');
        if ($returnedRequest !== $requestedUrl || $final === null || ! AuditUrl::sameSite($final, $domain)
            || (str_starts_with($requestedUrl, 'https:') && ! str_starts_with($final, 'https:'))) {
            throw new PageSpeedException('url_mismatch', 'PageSpeed measured a different or unsupported website URL.');
        }
        $device = data_get($lab, 'configSettings.formFactor') ?? data_get($lab, 'configSettings.emulatedFormFactor');
        if ($device !== $strategy) {
            throw new PageSpeedException('device_mismatch', 'PageSpeed returned a different or missing device configuration.');
        }
        $score = data_get($lab, 'categories.performance.score');
        if (! is_int($score) && ! is_float($score) || $score < 0 || $score > 1) {
            throw new PageSpeedException('missing_score', 'PageSpeed did not return a valid performance score.', true);
        }
        try {
            $time = $lab['fetchTime'] ?? null;
            if (! is_string($time) || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $time)) {
                throw new \RuntimeException;
            }
            $measuredAt = CarbonImmutable::parse($time)->utc();
            if ($measuredAt->gt(now()->addMinutes(5))) {
                throw new \RuntimeException;
            }
        } catch (Throwable) {
            throw new PageSpeedException('invalid_timestamp', 'PageSpeed did not return a valid measurement time.', true);
        }
        $metrics = [];
        foreach (self::METRICS as $id => $unit) {
            $value = data_get($lab, 'audits.'.$id.'.numericValue');
            $metrics[$id] = ['value' => $this->number($value), 'unit' => $unit];
        }
        $diagnostics = [];
        foreach (is_array($lab['audits'] ?? null) ? $lab['audits'] : [] as $id => $audit) {
            if (! is_array($audit) || isset(self::METRICS[$id]) || ! in_array($audit['scoreDisplayMode'] ?? null, ['numeric', 'binary'], true)
                || $this->number($audit['score'] ?? null) === null || $audit['score'] >= 1) {
                continue;
            }
            $diagnostics[] = ['id' => mb_substr((string) $id, 0, 100), 'title' => $this->text($audit['title'] ?? null),
                'display_value' => $this->text($audit['displayValue'] ?? null),
                'estimated_savings_ms' => $this->number(data_get($audit, 'details.overallSavingsMs'))];
            if (count($diagnostics) >= 10) {
                break;
            }
        }
        $score = (int) round($score * 100);
        $poor = min(100, max(0, (int) config('pagespeed.poor_score_below')));
        $good = min(100, max($poor, (int) config('pagespeed.good_score_from')));
        $rating = $score < $poor ? 'poor' : ($score < $good ? 'needs_improvement' : 'good');

        return [
            'final_url' => $final, 'measured_at' => $measuredAt, 'performance_score' => $score,
            'lighthouse_version' => mb_substr($this->text($lab['lighthouseVersion'] ?? null) ?? '', 0, 64) ?: null,
            'result' => [
                'version' => 1, 'lab' => ['source' => 'Lighthouse', 'strategy' => $strategy, 'rating' => $rating, 'metrics' => $metrics, 'diagnostics' => $diagnostics,
                    'warnings' => array_values(array_filter(array_map(fn ($value) => $this->text($value), array_slice(is_array($lab['runWarnings'] ?? null) ? $lab['runWarnings'] : [], 0, 10))))],
                'field' => [
                    'page' => $this->field($data['loadingExperience'] ?? null, $final, $domain, false),
                    'origin' => $this->field($data['originLoadingExperience'] ?? null, $final, $domain, true),
                ],
                'drafting_evidence' => ['has_lab_speed_concern' => $rating !== 'good', 'scope' => 'This page and device in a Lighthouse lab test',
                    'observation' => match ($rating) {
                        'poor' => 'The measured page showed poor loading performance in our '.$strategy.' lab test.',
                        'needs_improvement' => 'Our '.$strategy.' lab test identified room to improve this page’s loading performance.',
                        default => null,
                    },
                    'limitation' => 'Lab results vary. This does not establish real-user Core Web Vitals, a ranking penalty, or lost sales.'],
            ],
        ];
    }

    private function field(mixed $experience, string $url, string $domain, bool $origin): array
    {
        $missing = ['status' => 'unavailable', 'scope' => $origin ? 'origin' : 'url', 'metrics' => []];
        if (! is_array($experience) || ! is_string($experience['id'] ?? null)) {
            return $missing;
        }
        $id = AuditUrl::normalize($experience['id']);
        if ($id === null || ! AuditUrl::sameSite($id, $domain)) {
            return $missing;
        }
        $scope = $origin || ($experience['origin_fallback'] ?? false) ? 'origin' : ($id === $url ? 'url' : 'other_url');
        $metrics = [];
        foreach (['LARGEST_CONTENTFUL_PAINT_MS', 'INTERACTION_TO_NEXT_PAINT', 'CUMULATIVE_LAYOUT_SHIFT_SCORE', 'FIRST_CONTENTFUL_PAINT_MS'] as $key) {
            $metric = $experience['metrics'][$key] ?? null;
            if (! is_array($metric) || $this->number($metric['percentile'] ?? null) === null) {
                continue;
            }
            $metrics[$key] = ['percentile' => $metric['percentile'], 'category' => in_array($metric['category'] ?? null, ['FAST', 'AVERAGE', 'SLOW'], true) ? $metric['category'] : null,
                'unit' => $key === 'CUMULATIVE_LAYOUT_SHIFT_SCORE' ? 'hundredths' : 'ms'];
        }

        return ['status' => $metrics === [] ? 'unavailable' : 'available', 'source' => 'CrUX via PageSpeed', 'scope' => $scope, 'id' => $id,
            'overall_category' => in_array($experience['overall_category'] ?? null, ['FAST', 'AVERAGE', 'SLOW'], true) ? $experience['overall_category'] : null, 'metrics' => $metrics];
    }

    private function number(mixed $value): int|float|null
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 ? $value : null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) ? mb_substr(trim(strip_tags($value)), 0, 500) : null;
    }
}
