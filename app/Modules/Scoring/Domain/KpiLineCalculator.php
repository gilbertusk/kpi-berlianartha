<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain;

use App\Modules\Scoring\Domain\Strategy\CalculatorRegistry;
use App\Modules\Scoring\Domain\Strategy\Parameters;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

/**
 * Scores one KPI line, in this order:
 *
 * 1. Eligibility. Late entries are dropped when the policy says so. Any
 *    remaining entry that is not verified makes the line wait
 *    (PendingVerification): a score never uses half-verified data.
 * 2. Score values the definition states (floor, cap, band, yes/no, or stage
 *    scores) with more decimals than the KPI rounding → not scorable, since
 *    rounding would move a score past a signed bound.
 * 3. Verified N/A entries are left out of the values. Only N/A → the line is
 *    N/A. No entry at all → the KPI's signed missing_data_rule.
 * 4. Value entries outside the definition's valid range → not scorable.
 * 5. The signed aggregation gives the measured value of the period.
 * 6. The strategy of the pinned definition version gives an exact score.
 * 7. The KPI's signed rounding gives the KPI score.
 */
final readonly class KpiLineCalculator
{
    private const MISSING_RULES = ['mark_not_applicable', 'score_zero', 'block_until_provided'];

    public function __construct(private CalculatorRegistry $calculators) {}

    public function calculate(KpiLineInput $line, string $lateDataRule): KpiLineResult
    {
        $trace = self::trace($line, $lateDataRule);
        $decisions = array_column($trace, 'decision');
        $calculator = $this->calculators->find($line->strategyKey, $line->strategyVersion);

        if ($calculator === null) {
            return self::result($line, KpiDataStatus::Unsupported, 'strategy_not_supported', $trace);
        }

        if (in_array('pending', $decisions, true)) {
            return self::result($line, KpiDataStatus::PendingVerification, null, $trace);
        }

        try {
            $rounding = RoundingRule::of($line->parameters['rounding_mode'] ?? null, $line->parameters['rounding_scale'] ?? null);

            foreach ($calculator->configuredScores($line->parameters) as $configured) {
                if (! $rounding->apply($configured)->isEqualTo($configured)) {
                    return self::result($line, KpiDataStatus::InvalidInput, 'score_finer_than_rounding', $trace);
                }
            }

            $target = Exact::parseNullable($line->targetValue);
            $values = [];

            foreach ($trace as $row) {
                if ($row['decision'] === 'used') {
                    $values[] = Exact::parse((string) $row['value']);
                }
            }

            // A KPI scored against a target is never zeroed or dropped by the
            // missing-data rule while its final target does not exist: the
            // real gap is the target, and it must be visible as such.
            if ($values === [] && $calculator->requiresTarget() && $target === null) {
                return self::result($line, KpiDataStatus::TargetMissing, null, $trace);
            }

            if ($values === []) {
                return in_array('not_applicable', $decisions, true)
                    ? self::result($line, KpiDataStatus::NotApplicable, 'declared_not_applicable', $trace, target: $target)
                    : self::missing($line, $rounding, $target, $trace, $decisions !== [] && array_unique($decisions) === ['excluded_late']);
            }

            if (! self::withinValidRange($line, $values)) {
                return self::result($line, KpiDataStatus::InvalidInput, 'value_out_of_range', $trace, target: $target);
            }

            $measured = ActualAggregation::aggregate($line->aggregation, $values);

            if ($measured === null) {
                return self::result($line, KpiDataStatus::Unsupported, 'aggregation_not_supported', $trace, target: $target);
            }

            if ($calculator->requiresTarget() && $target === null) {
                return self::result($line, KpiDataStatus::TargetMissing, null, $trace, $measured);
            }

            $outcome = $calculator->calculate($measured, $target, $line->parameters);

            if ($outcome->score === null) {
                return self::result($line, $outcome->status, $outcome->note, $trace, $measured, $target, $outcome->achievement);
            }

            return self::result($line, KpiDataStatus::Scored, $outcome->note, $trace, $measured, $target, $outcome->achievement, $outcome->score, $rounding->apply($outcome->score));
        } catch (InvalidArgumentException|MathException) {
            return self::result($line, KpiDataStatus::InvalidInput, 'invalid_parameters', $trace);
        }
    }

    /**
     * One row per entry with the decision taken: used, not_applicable,
     * pending (not verified), or excluded_late.
     *
     * @return list<array<string, mixed>>
     */
    private static function trace(KpiLineInput $line, string $lateDataRule): array
    {
        $trace = [];

        foreach ($line->sortedEntries() as $entry) {
            $trace[] = [...$entry->toArray(), 'decision' => match (true) {
                $entry->isLate && $lateDataRule === 'exclude' => 'excluded_late',
                ! $entry->isVerified() => 'pending',
                ! $entry->isValue() => 'not_applicable',
                default => 'used',
            }];
        }

        return $trace;
    }

    /**
     * @param  list<array<string, mixed>>  $trace
     */
    private static function missing(KpiLineInput $line, RoundingRule $rounding, ?BigRational $target, array $trace, bool $onlyLateEntries = false): KpiLineResult
    {
        // When entries exist but the policy excluded all of them as late, the
        // note says so: the line is "missing" because of the late-data rule.
        $suffix = $onlyLateEntries ? '_late_excluded' : '';

        return match (Parameters::choice($line->parameters, 'missing_data_rule', self::MISSING_RULES)) {
            'score_zero' => self::result($line, KpiDataStatus::MissingScoredZero, 'missing_score_zero'.$suffix, $trace, null, $target, null, BigRational::zero(), $rounding->apply(BigRational::zero())),
            'mark_not_applicable' => self::result($line, KpiDataStatus::MissingNotApplicable, 'missing_not_applicable'.$suffix, $trace, target: $target),
            default => self::result($line, KpiDataStatus::MissingBlocked, 'missing_blocked'.$suffix, $trace, target: $target),
        };
    }

    /**
     * @param  list<BigRational>  $values
     */
    private static function withinValidRange(KpiLineInput $line, array $values): bool
    {
        $minimum = Exact::parseNullable($line->minValidValue);
        $maximum = Exact::parseNullable($line->maxValidValue);

        foreach ($values as $value) {
            if (($minimum !== null && $value->isLessThan($minimum)) || ($maximum !== null && $value->isGreaterThan($maximum))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $trace
     */
    private static function result(
        KpiLineInput $line,
        KpiDataStatus $status,
        ?string $note,
        array $trace,
        ?BigRational $measured = null,
        ?BigRational $target = null,
        ?BigRational $achievement = null,
        ?BigRational $rawScore = null,
        ?BigDecimal $score = null,
    ): KpiLineResult {
        return new KpiLineResult($line, $status, $note, $measured, $target, $achievement, $rawScore, $score, null, $trace);
    }
}
