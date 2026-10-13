<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain;

use Brick\Math\BigDecimal;

/**
 * Pure, deterministic scorecard calculation (PRD §7.4, FR 009). Rounding
 * order, each step applied once to an exact value:
 *
 * 1. KPI score: the KPI definition's signed rounding (per line).
 * 2. Contribution = KPI score × weight ÷ denominator, rounded by the policy's
 *    contribution rule. The denominator is the total assigned weight (100),
 *    or the weight of the scored KPIs when the policy redistributes the
 *    weight of N/A KPIs.
 * 3. Contribution total = the exact sum of the rounded contributions.
 * 4. Final score = contribution total rounded by the policy's final rule.
 * 5. Rating = the policy band that holds the final score.
 *
 * Same input and same policy version give the same result, byte for byte.
 * A change in behaviour needs a new ENGINE_VERSION; stored calculations keep
 * the version that produced them.
 */
final readonly class ScorecardCalculator
{
    public const ENGINE_VERSION = 1;

    public function __construct(private KpiLineCalculator $lines) {}

    public static function fingerprint(ScorecardInput $input, ScorecardPolicy $policy): string
    {
        return Canonical::hash([
            'engine' => self::ENGINE_VERSION,
            'policy' => $policy->rules(),
            'input' => $input->toArray(),
        ]);
    }

    public function calculate(ScorecardInput $input, ScorecardPolicy $policy): ScorecardResult
    {
        $lines = array_map(fn (KpiLineInput $line): KpiLineResult => $this->lines->calculate($line, $policy->lateDataRule), $input->lines);
        $assigned = self::weight($lines, static fn (KpiLineResult $line): bool => true);
        $counted = self::weight($lines, static fn (KpiLineResult $line): bool => $line->status->hasScore());
        $excluded = self::weight($lines, static fn (KpiLineResult $line): bool => $line->status->isExcluded());
        $blockers = self::blockers($lines, $assigned, $counted, $excluded, $policy);

        if ($blockers !== []) {
            return new ScorecardResult(ScorecardStatus::Incomplete, $blockers, $lines, $assigned, $counted, $excluded, null, null, null);
        }

        $denominator = $policy->notApplicableWeightRule === 'redistribute_proportionally' ? $counted : $assigned;
        $total = BigDecimal::zero();

        foreach ($lines as $index => $line) {
            if ($line->score === null || ! $line->status->hasScore()) {
                continue;
            }

            $contribution = $policy->contributionRounding->apply(
                $line->score->toBigRational()->multipliedBy(Exact::parse($line->input->weight))->dividedBy($denominator),
            );

            $lines[$index] = $line->withContribution($contribution);
            $total = $total->plus($contribution);
        }

        $final = $policy->finalRounding->apply($total->toBigRational());
        $rating = $policy->ratingFor($final);

        return new ScorecardResult(
            $rating === null ? ScorecardStatus::Incomplete : ScorecardStatus::Complete,
            $rating === null ? ['rating_out_of_range'] : [],
            $lines,
            $assigned,
            $counted,
            $excluded,
            $total,
            $final,
            $rating,
        );
    }

    /**
     * @param  list<KpiLineResult>  $lines
     * @return list<string>
     */
    private static function blockers(array $lines, BigDecimal $assigned, BigDecimal $counted, BigDecimal $excluded, ScorecardPolicy $policy): array
    {
        if ($lines === []) {
            return ['no_lines'];
        }

        $blockers = [];

        foreach ($lines as $line) {
            if ($line->status->blocks()) {
                $blockers[] = 'kpi_blocked';

                break;
            }
        }

        if (! $assigned->isEqualTo(100)) {
            $blockers[] = 'weight_not_100';
        }

        if ($excluded->isPositive() && $policy->notApplicableWeightRule === 'block_until_resolved') {
            $blockers[] = 'not_applicable_blocked';
        }

        if ($blockers === [] && $counted->isZero()) {
            $blockers[] = 'no_scored_kpi';
        }

        return $blockers;
    }

    /**
     * @param  list<KpiLineResult>  $lines
     * @param  callable(KpiLineResult): bool  $include
     */
    private static function weight(array $lines, callable $include): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($lines as $line) {
            if ($include($line)) {
                $total = $total->plus(BigDecimal::of($line->input->weight));
            }
        }

        return $total;
    }
}
