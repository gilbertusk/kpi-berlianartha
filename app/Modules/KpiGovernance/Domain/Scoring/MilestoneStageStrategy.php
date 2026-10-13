<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Domain\Scoring;

/**
 * PRD §7.2 "Milestone": ordered stages, each with an approved score.
 */
final class MilestoneStageStrategy implements ScoreStrategy
{
    private const MAX_STAGES = 20;

    public function key(): string
    {
        return 'milestone_stage';
    }

    public function version(): int
    {
        return 1;
    }

    public function label(): string
    {
        return 'Skor per tahap milestone';
    }

    public function supportedDirections(): array
    {
        return ['milestone'];
    }

    public function validate(array $parameters): array
    {
        $errors = CommonScoreRules::validate($parameters);
        $stages = $parameters['stages'] ?? null;

        if (! is_array($stages) || count($stages) < 2 || count($stages) > self::MAX_STAGES) {
            return [...$errors, 'Milestone wajib memiliki 2 sampai '.self::MAX_STAGES.' tahap.'];
        }

        $previous = null;

        foreach (array_values($stages) as $index => $stage) {
            $row = $index + 1;
            $label = is_array($stage) && is_string($stage['label'] ?? null) ? trim($stage['label']) : '';
            $score = is_array($stage) ? ScaledDecimal::parse($stage['score'] ?? null) : null;

            if ($label === '' || mb_strlen($label) > 80 || $score === null) {
                $errors[] = "Tahap {$row}: label (maks. 80 karakter) dan skor wajib diisi.";

                continue;
            }

            if ($previous !== null && $score < $previous) {
                $errors[] = "Tahap {$row}: skor tidak boleh turun dari tahap sebelumnya.";
            }

            $previous = $score;
        }

        $scores = array_map(fn (mixed $stage): mixed => is_array($stage) ? ($stage['score'] ?? null) : null, array_values($stages));

        return [...$errors, ...CommonScoreRules::validateScorePrecision($parameters, $scores)];
    }

    public function normalize(array $parameters): array
    {
        /** @var list<array<string, mixed>> $stages */
        $stages = array_values($parameters['stages']);

        return [
            ...CommonScoreRules::normalize($parameters),
            'stages' => array_map(fn (array $stage): array => [
                'label' => trim((string) $stage['label']),
                'score' => ScaledDecimal::normalize($stage['score']),
            ], $stages),
        ];
    }

    public function describe(array $parameters): string
    {
        /** @var list<array<string, string>> $stages */
        $stages = $parameters['stages'] ?? [];

        return sprintf(
            '%s: %s; %s',
            $this->label(),
            implode(' → ', array_map(fn (array $stage): string => "{$stage['label']} ({$stage['score']})", $stages)),
            CommonScoreRules::describe($parameters),
        );
    }
}
