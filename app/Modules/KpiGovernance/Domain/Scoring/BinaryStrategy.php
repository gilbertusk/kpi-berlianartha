<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Domain\Scoring;

/**
 * PRD §7.2 "Binary": achieved / not achieved, each with a configured score.
 */
final class BinaryStrategy implements ScoreStrategy
{
    public function key(): string
    {
        return 'binary';
    }

    public function version(): int
    {
        return 1;
    }

    public function label(): string
    {
        return 'Tercapai / tidak tercapai';
    }

    public function supportedDirections(): array
    {
        return ['binary'];
    }

    public function validate(array $parameters): array
    {
        $errors = CommonScoreRules::validate($parameters);
        $achieved = ScaledDecimal::parse($parameters['achieved_score'] ?? null);
        $notAchieved = ScaledDecimal::parse($parameters['not_achieved_score'] ?? null);

        if ($achieved === null || $notAchieved === null) {
            $errors[] = 'Skor tercapai dan tidak tercapai wajib diisi.';
        } elseif ($notAchieved > $achieved) {
            $errors[] = 'Skor tidak tercapai tidak boleh lebih besar dari skor tercapai.';
        }

        return [...$errors, ...CommonScoreRules::validateScorePrecision($parameters, [$parameters['achieved_score'] ?? null, $parameters['not_achieved_score'] ?? null])];
    }

    public function normalize(array $parameters): array
    {
        return [
            ...CommonScoreRules::normalize($parameters),
            'achieved_score' => ScaledDecimal::normalize($parameters['achieved_score']),
            'not_achieved_score' => ScaledDecimal::normalize($parameters['not_achieved_score']),
        ];
    }

    public function describe(array $parameters): string
    {
        return sprintf('%s: tercapai → %s, tidak → %s; %s', $this->label(), $parameters['achieved_score'] ?? '—', $parameters['not_achieved_score'] ?? '—', CommonScoreRules::describe($parameters));
    }
}
