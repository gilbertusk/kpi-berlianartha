<?php

declare(strict_types=1);

use App\Modules\Scoring\Domain\Exact;
use App\Modules\Scoring\Domain\KpiDataStatus;
use App\Modules\Scoring\Domain\KpiLineInput;
use App\Modules\Scoring\Domain\ScorecardStatus;
use Tests\Support\ScoringFixtures as F;

/*
 * Golden examples GE-01 … GE-04 from docs/product/SCORING_GOLDEN_EXAMPLES.md.
 * SYNTHETIC and NOT SIGNED: the numbers only prove the engine matches the
 * manual arithmetic written next to each example. Business-signed examples
 * replace them once DEC-004 and DEC-006 are decided.
 */

function goldenQualityLine(): KpiLineInput
{
    return F::line([
        'kpiCode' => 'GE-QUALITY',
        'weight' => '50.0000',
        'targetValue' => '98.0000',
        'aggregation' => 'average',
        'entries' => [F::entry('97.5', '2026-11-02'), F::entry('98.2', '2026-11-09'), F::entry('99.1', '2026-11-16')],
    ]);
}

test('GE-01: three KPIs, all scored, reconcile to the final score', function (): void {
    $reject = F::line([
        'kpiCode' => 'GE-REJECT',
        'weight' => '30.0000',
        'targetValue' => '12.0000',
        'strategyKey' => 'inverse_ratio_to_target',
        'aggregation' => 'sum',
        'parameters' => F::ratioParameters(['zero_actual_rule' => 'score_cap']),
        'entries' => [F::entry('4', '2026-11-02'), F::entry('5', '2026-11-09'), F::entry('2', '2026-11-16')],
    ]);
    $discipline = F::line([
        'kpiCode' => 'GE-DISCIPLINE',
        'weight' => '20.0000',
        'targetValue' => null,
        'strategyKey' => 'binary',
        'aggregation' => 'last_value',
        'parameters' => ['missing_data_rule' => 'block_until_provided', 'rounding_mode' => 'half_up', 'rounding_scale' => 0, 'achieved_score' => '100', 'not_achieved_score' => '0'],
        'entries' => [F::entry('1', '2026-11-30')],
    ]);

    $result = F::engine()->calculate(F::card([goldenQualityLine(), $reject, $discipline]), F::policy());
    $lines = $result->lines;

    // GE-QUALITY: (97.5 + 98.2 + 99.1) ÷ 3 = 98.2666…; ÷ 98 × 100 = 100.2721… → 100.27; × 50% = 50.1350.
    expect(Exact::fraction($lines[0]->measured))->toBe('1474/15')
        ->and(Exact::describe($lines[0]->achievement))->toBe('≈100.27210884')
        ->and($lines[0]->score->toString())->toBe('100.27')
        ->and($lines[0]->contribution->toString())->toBe('50.1350')
        // GE-REJECT: 4 + 5 + 2 = 11; 12 ÷ 11 × 100 = 109.0909… → 109.09; × 30% = 32.7270.
        ->and(Exact::describe($lines[1]->measured))->toBe('11')
        ->and($lines[1]->score->toString())->toBe('109.09')
        ->and($lines[1]->contribution->toString())->toBe('32.7270')
        // GE-DISCIPLINE: Ya → 100; × 20% = 20.0000.
        ->and($lines[2]->score->toString())->toBe('100')
        ->and($lines[2]->contribution->toString())->toBe('20.0000')
        // 50.1350 + 32.7270 + 20.0000 = 102.8620 → 102.86 → band [100, ∞).
        ->and($result->contributionTotal->toString())->toBe('102.8620')
        ->and($result->finalScore->toString())->toBe('102.86')
        ->and($result->rating->key)->toBe('r4')
        ->and($result->status)->toBe(ScorecardStatus::Complete);
});

test('GE-02: an N/A KPI under each signed N/A weight rule', function (string $rule, string $contribution, string $final, string $rating): void {
    $result = F::engine()->calculate(F::card([
        F::line(['kpiCode' => 'GE-A', 'weight' => '60.0000', 'entries' => [F::entry('88', '2026-11-02'), F::entry('92', '2026-11-09')]]),
        F::line(['kpiCode' => 'GE-B', 'weight' => '40.0000', 'entries' => [F::entry(null, '2026-11-30')]]),
    ]), F::policy(['not_applicable_weight_rule' => $rule]));

    // GE-A: (88 + 92) ÷ 2 = 90 → 90.00. GE-B: N/A, never zero.
    expect($result->lines[0]->score->toString())->toBe('90.00')
        ->and($result->lines[1]->status)->toBe(KpiDataStatus::NotApplicable)
        ->and($result->lines[0]->contribution->toString())->toBe($contribution)
        ->and($result->finalScore->toString())->toBe($final)
        ->and($result->rating->key)->toBe($rating);
})->with([
    // 90.00 × 60 ÷ 60 = 90.0000 → 90.00.
    'redistribute' => ['redistribute_proportionally', '90.0000', '90.00', 'r3'],
    // 90.00 × 60 ÷ 100 = 54.0000 → 54.00.
    'keep full denominator' => ['keep_full_denominator', '54.0000', '54.00', 'r1'],
]);

test('GE-03: missing data held by the KPI rule leaves the scorecard incomplete', function (): void {
    $result = F::engine()->calculate(F::card([
        F::line(['kpiCode' => 'GE-A', 'weight' => '60.0000', 'entries' => [F::entry('90')]]),
        F::line(['kpiCode' => 'GE-B', 'weight' => '40.0000', 'entries' => []]),
    ]), F::policy());

    expect($result->status)->toBe(ScorecardStatus::Incomplete)
        ->and($result->blockers)->toBe(['kpi_blocked'])
        ->and($result->lines[1]->status)->toBe(KpiDataStatus::MissingBlocked)
        ->and($result->finalScore)->toBeNull()
        ->and($result->rating)->toBeNull();
});

test('GE-04: rounding order and a rating boundary', function (string $actual, string $target, int $kpiScale, string $score, string $final, string $rating): void {
    $result = F::engine()->calculate(F::card([F::line([
        'targetValue' => $target,
        'parameters' => F::ratioParameters(['rounding_scale' => $kpiScale]),
        'entries' => [F::entry($actual)],
    ])]), F::policy());

    expect($result->lines[0]->score->toString())->toBe($score)
        ->and($result->finalScore->toString())->toBe($final)
        ->and($result->rating->key)->toBe($rating);
})->with([
    // 2 ÷ 3 × 100 = 66.666… → KPI 66.67 → contribution 66.6700 → final 66.67 → [60, 80).
    'non-terminating ratio' => ['2', '3', 2, '66.67', '66.67', 'r2'],
    // 79.995 → KPI 79.9950 (4 decimals) → final 80.00 (half up) → [80, 100), not [60, 80).
    'boundary after final rounding' => ['79.995', '100', 4, '79.9950', '80.00', 'r3'],
]);
