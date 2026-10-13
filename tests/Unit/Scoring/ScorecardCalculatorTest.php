<?php

declare(strict_types=1);

use App\Modules\Scoring\Domain\Exact;
use App\Modules\Scoring\Domain\KpiDataStatus;
use App\Modules\Scoring\Domain\KpiLineInput;
use App\Modules\Scoring\Domain\ScorecardCalculator;
use App\Modules\Scoring\Domain\ScorecardStatus;
use Brick\Math\BigDecimal;
use Tests\Support\ScoringFixtures as F;

/*
 * Scorecard totals with the synthetic TEST-ONLY policy (not DEC-004).
 */

function scoredLine(string $code, string $weight, string $actual, string $target = '100', array $parameters = []): KpiLineInput
{
    return F::line([
        'kpiCode' => $code,
        'weight' => $weight,
        'targetValue' => $target,
        'parameters' => F::ratioParameters($parameters),
        'entries' => [F::entry($actual)],
    ]);
}

test('the final score is the sum of rounded contributions and reconciles to each KPI', function (): void {
    $result = F::engine()->calculate(F::card([
        scoredLine('A', '50.0000', '95.5'),
        scoredLine('B', '30.0000', '110'),
        scoredLine('C', '20.0000', '130'),
    ]), F::policy());

    // A 95.50 × 50% = 47.75; B 110.00 × 30% = 33.00; C capped 120.00 × 20% = 24.00.
    expect($result->status)->toBe(ScorecardStatus::Complete)
        ->and(array_map(fn ($line) => $line->score->toString(), $result->lines))->toBe(['95.50', '110.00', '120.00'])
        ->and(array_map(fn ($line) => $line->contribution->toString(), $result->lines))->toBe(['47.7500', '33.0000', '24.0000'])
        ->and($result->contributionTotal->toString())->toBe('104.7500')
        ->and($result->finalScore->toString())->toBe('104.75')
        ->and($result->rating->key)->toBe('r4');

    $sum = BigDecimal::zero();
    foreach ($result->lines as $line) {
        $sum = $sum->plus($line->contribution);
    }
    expect($sum->isEqualTo($result->contributionTotal))->toBeTrue();
});

test('the N/A weight rule decides the denominator and is never a silent zero', function (string $rule, ScorecardStatus $status, ?string $final, array $blockers): void {
    $card = F::card([
        scoredLine('A', '60.0000', '90'),
        F::line(['kpiCode' => 'B', 'weight' => '40.0000', 'entries' => [F::entry(null)]]),
    ]);

    $result = F::engine()->calculate($card, F::policy(['not_applicable_weight_rule' => $rule]));

    expect($result->status)->toBe($status)
        ->and($result->finalScore?->toString())->toBe($final)
        ->and($result->blockers)->toBe($blockers)
        ->and($result->lines[1]->status)->toBe(KpiDataStatus::NotApplicable)
        ->and($result->lines[1]->contribution)->toBeNull()
        ->and($result->excludedWeight->toString())->toBe('40.0000');
})->with([
    // 90 × 60 ÷ 60 = 90.
    'redistribute' => ['redistribute_proportionally', ScorecardStatus::Complete, '90.00', []],
    // 90 × 60 ÷ 100 = 54: the policy states that N/A weight is lost.
    'keep full denominator' => ['keep_full_denominator', ScorecardStatus::Complete, '54.00', []],
    'block' => ['block_until_resolved', ScorecardStatus::Incomplete, null, ['not_applicable_blocked']],
]);

test('a blocked KPI makes the scorecard incomplete without a final score or rating', function (): void {
    $result = F::engine()->calculate(F::card([
        scoredLine('A', '70.0000', '90'),
        F::line(['kpiCode' => 'B', 'weight' => '30.0000']),
    ]), F::policy());

    expect($result->status)->toBe(ScorecardStatus::Incomplete)
        ->and($result->blockers)->toBe(['kpi_blocked'])
        ->and($result->finalScore)->toBeNull()
        ->and($result->contributionTotal)->toBeNull()
        ->and($result->rating)->toBeNull()
        ->and($result->lines[0]->score->toString())->toBe('90.00')
        ->and($result->lines[0]->contribution)->toBeNull()
        ->and($result->lines[1]->status)->toBe(KpiDataStatus::MissingBlocked);
});

test('applicable weights that do not total 100 are not normalized by guess', function (): void {
    $result = F::engine()->calculate(F::card([scoredLine('A', '50.0000', '90'), scoredLine('B', '30.0000', '90')]), F::policy());

    expect($result->status)->toBe(ScorecardStatus::Incomplete)
        ->and($result->blockers)->toBe(['weight_not_100'])
        ->and($result->assignedWeight->toString())->toBe('80.0000');
});

test('a scorecard without lines or without any scored KPI has no final score', function (): void {
    expect(F::engine()->calculate(F::card([]), F::policy())->blockers)->toBe(['no_lines']);

    $allNa = F::engine()->calculate(F::card([F::line(['entries' => [F::entry(null)]])]), F::policy(['not_applicable_weight_rule' => 'keep_full_denominator']));

    expect($allNa->status)->toBe(ScorecardStatus::Incomplete)
        ->and($allNa->blockers)->toBe(['no_scored_kpi'])
        ->and($allNa->finalScore)->toBeNull();
});

test('contribution and final rounding follow the policy, in that order', function (array $contribution, array $final, string $contributionValue, string $finalValue): void {
    // One KPI, weight 100: 2 ÷ 3 × 100 = 66.666…%, KPI rounding 4 decimals → 66.6667.
    $line = F::line(['targetValue' => '3', 'parameters' => F::ratioParameters(['rounding_scale' => 4]), 'entries' => [F::entry('2')]]);
    $policy = F::policy(['contribution_rounding' => $contribution, 'final_rounding' => $final]);

    $result = F::engine()->calculate(F::card([$line]), $policy);

    expect($result->lines[0]->score->toString())->toBe('66.6667')
        ->and($result->lines[0]->contribution->toString())->toBe($contributionValue)
        ->and($result->finalScore->toString())->toBe($finalValue);
})->with([
    [['mode' => 'half_up', 'scale' => 4], ['mode' => 'half_up', 'scale' => 2], '66.6667', '66.67'],
    [['mode' => 'down', 'scale' => 1], ['mode' => 'half_up', 'scale' => 2], '66.6', '66.60'],
    [['mode' => 'half_up', 'scale' => 4], ['mode' => 'down', 'scale' => 0], '66.6667', '66'],
]);

test('rating boundaries: exactly at a band minimum belongs to that band', function (string $actual, string $rating): void {
    $result = F::engine()->calculate(F::card([F::line(['parameters' => F::ratioParameters(['rounding_scale' => 4]), 'entries' => [F::entry($actual)]])]), F::policy(['final_rounding' => ['mode' => 'half_up', 'scale' => 4]]));

    expect($result->rating->key)->toBe($rating);
})->with([
    ['59.9999', 'r1'],
    ['60', 'r2'],
    ['60.0001', 'r2'],
    ['79.9999', 'r2'],
    ['80', 'r3'],
    ['99.9999', 'r3'],
    ['100', 'r4'],
]);

test('final rounding happens before the rating band is chosen', function (): void {
    // 79.995 → 80.00 with half up at 2 decimals → band r3, not r2.
    $result = F::engine()->calculate(F::card([F::line(['parameters' => F::ratioParameters(['rounding_scale' => 4]), 'entries' => [F::entry('79.995')]])]), F::policy());

    expect($result->finalScore->toString())->toBe('80.00')->and($result->rating->key)->toBe('r3');
});

test('a final score outside every band is flagged instead of rated', function (): void {
    $policy = F::policy(['rating_bands' => [['key' => 'only', 'label' => 'Uji', 'min' => '50', 'max' => '100']]]);
    $result = F::engine()->calculate(F::card([F::line(['entries' => [F::entry('40')]])]), $policy);

    expect($result->status)->toBe(ScorecardStatus::Incomplete)
        ->and($result->blockers)->toBe(['rating_out_of_range'])
        ->and($result->finalScore->toString())->toBe('40.00')
        ->and($result->rating)->toBeNull();
});

test('the fingerprint changes with any input or rule and never otherwise', function (): void {
    $card = F::card([scoredLine('A', '100.0000', '90')]);
    $policy = F::policy();
    $fingerprint = ScorecardCalculator::fingerprint($card, $policy);

    expect(ScorecardCalculator::fingerprint(F::card([scoredLine('A', '100.0000', '90')]), F::policy()))->toBe($fingerprint)
        ->and(ScorecardCalculator::fingerprint(F::card([scoredLine('A', '100.0000', '90.0001')]), $policy))->not->toBe($fingerprint)
        ->and(ScorecardCalculator::fingerprint($card, F::policy(['version' => 2])))->not->toBe($fingerprint)
        ->and(ScorecardCalculator::fingerprint(F::card([scoredLine('A', '100.0000', '90', parameters: ['score_cap' => '150'])]), $policy))->not->toBe($fingerprint);
});

test('the same input and policy always produce the same result', function (): void {
    $card = F::card([scoredLine('A', '50.0000', '91.2345'), scoredLine('B', '50.0000', '77')]);

    expect(F::engine()->calculate($card, F::policy())->toArray())->toBe(F::engine()->calculate($card, F::policy())->toArray())
        ->and(Exact::describe(F::engine()->calculate($card, F::policy())->lines[0]->measured))->toBe('91.2345');
});
