<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain\Strategy;

use Brick\Math\BigRational;

/**
 * ratio_to_target@1 (higher is better) and inverse_ratio_to_target@1 (lower
 * is better), PRD §7.2.
 *
 * Higher is better:
 * 1. target < 0 → not scorable (negative_target);
 * 2. target = 0 → the signed zero_target_rule (N/A, cap, or floor);
 * 3. otherwise achievement = actual ÷ target × 100, and the score is the
 *    achievement bounded by score_floor and score_cap. A negative actual
 *    gives a negative achievement and therefore the floor.
 *
 * Lower is better:
 * 1. target < 0 or actual < 0 → not scorable (negative_target, negative_actual);
 * 2. actual = 0 → the signed zero_actual_rule (cap or N/A); this also covers
 *    actual = target = 0;
 * 3. target = 0 → the signed zero_target_rule;
 * 4. otherwise achievement = target ÷ actual × 100, bounded by floor and cap.
 *
 * Achievement and score stay exact; the KPI's signed rounding is applied by
 * the scorecard calculator afterwards.
 */
final readonly class RatioToTargetCalculator implements ScoreCalculator
{
    private const ZERO_RULES = ['not_applicable', 'score_cap', 'score_floor'];

    public function __construct(private bool $inverse = false) {}

    public function key(): string
    {
        return $this->inverse ? 'inverse_ratio_to_target' : 'ratio_to_target';
    }

    public function version(): int
    {
        return 1;
    }

    public function requiresTarget(): bool
    {
        return true;
    }

    public function configuredScores(array $parameters): array
    {
        return [Parameters::decimal($parameters, 'score_floor'), Parameters::decimal($parameters, 'score_cap')];
    }

    public function calculate(BigRational $measured, ?BigRational $target, array $parameters): StrategyOutcome
    {
        if ($target === null) {
            return StrategyOutcome::invalid('target_missing');
        }

        $floor = Parameters::decimal($parameters, 'score_floor');
        $cap = Parameters::decimal($parameters, 'score_cap');

        if ($target->isNegative()) {
            return StrategyOutcome::invalid('negative_target');
        }

        if ($this->inverse) {
            if ($measured->isNegative()) {
                return StrategyOutcome::invalid('negative_actual');
            }

            if ($measured->isZero()) {
                return self::zeroRule(Parameters::choice($parameters, 'zero_actual_rule', ['score_cap', 'not_applicable']), 'zero_actual', $floor, $cap);
            }
        }

        if ($target->isZero()) {
            return self::zeroRule(Parameters::choice($parameters, 'zero_target_rule', self::ZERO_RULES), 'zero_target', $floor, $cap);
        }

        $achievement = $this->inverse
            ? $target->dividedBy($measured)->multipliedBy(100)
            : $measured->dividedBy($target)->multipliedBy(100);

        $note = match (true) {
            $achievement->isGreaterThan($cap) => 'capped',
            $achievement->isLessThan($floor) => 'floored',
            default => null,
        };

        return StrategyOutcome::scored($achievement->clamp($floor, $cap), $achievement, $note);
    }

    private static function zeroRule(string $rule, string $prefix, BigRational $floor, BigRational $cap): StrategyOutcome
    {
        return match ($rule) {
            'score_cap' => StrategyOutcome::scored($cap, null, "{$prefix}_score_cap"),
            'score_floor' => StrategyOutcome::scored($floor, null, "{$prefix}_score_floor"),
            default => StrategyOutcome::notApplicable("{$prefix}_not_applicable"),
        };
    }
}
