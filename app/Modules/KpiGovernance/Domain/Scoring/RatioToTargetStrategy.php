<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Domain\Scoring;

/**
 * PRD §7.2 "Higher is better": achievement = actual ÷ target × 100,
 * bounded by the configured floor and cap. With inverse = true it is the
 * "Lower is better" variant: target ÷ actual × 100, with an explicit rule
 * for actual = 0.
 */
final class RatioToTargetStrategy implements ScoreStrategy
{
    /** @var array<string, string> */
    public const ZERO_TARGET_RULES = [
        'not_applicable' => 'Tandai N/A',
        'score_cap' => 'Beri skor batas atas',
        'score_floor' => 'Beri skor batas bawah',
    ];

    /** @var array<string, string> */
    public const ZERO_ACTUAL_RULES = [
        'score_cap' => 'Beri skor batas atas',
        'not_applicable' => 'Tandai N/A',
    ];

    public function __construct(private readonly bool $inverse = false) {}

    public function key(): string
    {
        return $this->inverse ? 'inverse_ratio_to_target' : 'ratio_to_target';
    }

    public function version(): int
    {
        return 1;
    }

    public function label(): string
    {
        return $this->inverse ? 'Target ÷ aktual × 100 (lower is better)' : 'Aktual ÷ target × 100 (higher is better)';
    }

    public function supportedDirections(): array
    {
        return [$this->inverse ? 'lower_is_better' : 'higher_is_better'];
    }

    public function validate(array $parameters): array
    {
        $errors = [
            ...CommonScoreRules::validate($parameters),
            ...CommonScoreRules::validateCapFloor($parameters),
            ...CommonScoreRules::validateScorePrecision($parameters, [$parameters['score_floor'] ?? null, $parameters['score_cap'] ?? null]),
        ];

        $zeroTarget = CommonScoreRules::requireChoice($parameters, 'zero_target_rule', self::ZERO_TARGET_RULES, 'Aturan target nol wajib dipilih.');

        if ($zeroTarget !== null) {
            $errors[] = $zeroTarget;
        }

        if ($this->inverse) {
            $zeroActual = CommonScoreRules::requireChoice($parameters, 'zero_actual_rule', self::ZERO_ACTUAL_RULES, 'Aturan aktual nol wajib dipilih untuk KPI lower is better.');

            if ($zeroActual !== null) {
                $errors[] = $zeroActual;
            }
        }

        return $errors;
    }

    public function normalize(array $parameters): array
    {
        $normalized = [
            ...CommonScoreRules::normalize($parameters),
            'score_floor' => ScaledDecimal::normalize($parameters['score_floor']),
            'score_cap' => ScaledDecimal::normalize($parameters['score_cap']),
            'zero_target_rule' => (string) $parameters['zero_target_rule'],
        ];

        if ($this->inverse) {
            $normalized['zero_actual_rule'] = (string) $parameters['zero_actual_rule'];
        }

        return $normalized;
    }

    public function describe(array $parameters): string
    {
        $text = sprintf(
            '%s; skor %s–%s; target nol: %s',
            $this->label(),
            $parameters['score_floor'] ?? '—',
            $parameters['score_cap'] ?? '—',
            self::ZERO_TARGET_RULES[$parameters['zero_target_rule'] ?? ''] ?? '—',
        );

        if ($this->inverse) {
            $text .= '; aktual nol: '.(self::ZERO_ACTUAL_RULES[$parameters['zero_actual_rule'] ?? ''] ?? '—');
        }

        return $text.'; '.CommonScoreRules::describe($parameters);
    }
}
