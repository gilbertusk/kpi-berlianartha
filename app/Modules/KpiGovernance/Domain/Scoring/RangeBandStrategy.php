<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Domain\Scoring;

/**
 * PRD §7.2 "Range": the score comes from configured, non-overlapping
 * half-open bands [min, max) of the measured value.
 */
final class RangeBandStrategy implements ScoreStrategy
{
    private const MAX_BANDS = 20;

    public function key(): string
    {
        return 'range_band';
    }

    public function version(): int
    {
        return 1;
    }

    public function label(): string
    {
        return 'Skor berdasarkan rentang nilai';
    }

    public function supportedDirections(): array
    {
        return ['range', 'higher_is_better', 'lower_is_better'];
    }

    public function validate(array $parameters): array
    {
        $errors = CommonScoreRules::validate($parameters);
        $bands = $parameters['bands'] ?? null;

        if (! is_array($bands) || $bands === [] || count($bands) > self::MAX_BANDS) {
            return [...$errors, 'Rentang wajib berisi 1 sampai '.self::MAX_BANDS.' baris.'];
        }

        $previousMax = null;

        foreach (array_values($bands) as $index => $band) {
            $row = $index + 1;
            $min = is_array($band) ? ScaledDecimal::parse($band['min'] ?? null, true) : null;
            $max = is_array($band) ? ScaledDecimal::parse($band['max'] ?? null, true) : null;
            $score = is_array($band) ? ScaledDecimal::parse($band['score'] ?? null) : null;

            if ($min === null || $max === null || $score === null) {
                $errors[] = "Rentang baris {$row}: min, max, dan skor wajib berupa angka desimal.";

                continue;
            }

            if ($min >= $max) {
                $errors[] = "Rentang baris {$row}: min harus lebih kecil dari max.";
            }

            if ($previousMax !== null && $min < $previousMax) {
                $errors[] = "Rentang baris {$row} tumpang tindih atau tidak berurutan.";
            }

            $previousMax = $max;
        }

        if (ScaledDecimal::parse($parameters['out_of_band_score'] ?? null) === null) {
            $errors[] = 'Skor di luar rentang wajib diisi.';
        }

        $scores = array_map(fn (mixed $band): mixed => is_array($band) ? ($band['score'] ?? null) : null, array_values($bands));

        return [...$errors, ...CommonScoreRules::validateScorePrecision($parameters, [...$scores, $parameters['out_of_band_score'] ?? null])];
    }

    public function normalize(array $parameters): array
    {
        /** @var list<array<string, mixed>> $bands */
        $bands = array_values($parameters['bands']);

        return [
            ...CommonScoreRules::normalize($parameters),
            'bands' => array_map(fn (array $band): array => [
                'min' => ScaledDecimal::normalize($band['min'], true),
                'max' => ScaledDecimal::normalize($band['max'], true),
                'score' => ScaledDecimal::normalize($band['score']),
            ], $bands),
            'out_of_band_score' => ScaledDecimal::normalize($parameters['out_of_band_score']),
        ];
    }

    public function describe(array $parameters): string
    {
        /** @var list<array<string, string>> $bands */
        $bands = $parameters['bands'] ?? [];
        $text = implode(', ', array_map(fn (array $band): string => "[{$band['min']}, {$band['max']}) → {$band['score']}", $bands));

        return sprintf('%s: %s; di luar rentang → %s; %s', $this->label(), $text, $parameters['out_of_band_score'] ?? '—', CommonScoreRules::describe($parameters));
    }
}
