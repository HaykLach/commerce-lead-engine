<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ScoreReasonsCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Score reasons must be provided as an array.');
        }

        $normalized = array_map(static function (mixed $reason): array {
            if (is_string($reason)) {
                return [
                    'code' => 'generic',
                    'label' => $reason,
                    'weight' => null,
                    'impact' => null,
                ];
            }

            if (! is_array($reason)) {
                throw new InvalidArgumentException('Each score reason must be a string or structured array.');
            }

            return [
                'code' => $reason['code'] ?? 'generic',
                'label' => $reason['label'] ?? ($reason['description'] ?? 'Unlabeled reason'),
                'weight' => $reason['weight'] ?? null,
                'impact' => $reason['impact'] ?? null,
                'details' => $reason['details'] ?? [],
            ];
        }, $value);

        return json_encode($normalized, JSON_THROW_ON_ERROR);
    }
}
