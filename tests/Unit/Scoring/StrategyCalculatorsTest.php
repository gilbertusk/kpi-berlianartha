<?php

declare(strict_types=1);

use App\Modules\KpiGovernance\Domain\Scoring\ScoreStrategyRegistry;
use App\Modules\Scoring\Domain\Exact;
use App\Modules\Scoring\Domain\KpiDataStatus;
use App\Modules\Scoring\Domain\Strategy\BinaryCalculator;
use App\Modules\Scoring\Domain\Strategy\CalculatorRegistry;
use App\Modules\Scoring\Domain\Strategy\MilestoneStageCalculator;
use App\Modules\Scoring\Domain\Strategy\RangeBandCalculator;
use App\Modules\Scoring\Domain\Strategy\RatioToTargetCalculator;
use Tests\Support\ScoringFixtures;

/*
 * Table-driven tests per allowlisted strategy. Expected scores are exact
 * fractions written out by hand; parameters are synthetic (not DEC-004).
 */

test('every allowlisted strategy has exactly one calculation under the same key and version', function (): void {
    $strategies = new ScoreStrategyRegistry;
    $calculators = new CalculatorRegistry;

    foreach ($calculators->references() as $reference) {
        [$key, $version] = explode('@', $reference);
        expect($strategies->find($key, (int) $version))->not->toBeNull($reference);
    }

    foreach ($strategies->current() as $strategy) {
        expect($calculators->find($strategy->key(), $strategy->version()))->not->toBeNull($strategy->key());
    }
});

test('higher is better: actual ÷ target × 100 bounded by floor and cap', function (string $actual, string $target, string $score, ?string $achievement, ?string $note): void {
    $outcome = (new RatioToTargetCalculator)->calculate(Exact::parse($actual), Exact::parse($target), ScoringFixtures::ratioParameters(['score_floor' => '50']));

    expect($outcome->status)->toBe(KpiDataStatus::Scored)
        ->and(Exact::fraction($outcome->score))->toBe($score)
        ->and($outcome->achievement === null ? null : Exact::fraction($outcome->achievement))->toBe($achievement)
        ->and($outcome->note)->toBe($note);
})->with([
    'on target' => ['100', '100', '100', '100', null],
    'below target' => ['95.5', '100', '191/2', '191/2', null],
    'exactly at cap' => ['120', '100', '120', '120', null],
    'just above cap' => ['120.0001', '100', '120', '1200001/10000', 'capped'],
    'exactly at floor' => ['50', '100', '50', '50', null],
    'just below floor' => ['49.9999', '100', '50', '499999/10000', 'floored'],
    'non-terminating ratio' => ['90', '97', '9000/97', '9000/97', null],
    'negative actual is floored' => ['-5', '100', '50', '-5', 'floored'],
    'zero actual is floored' => ['0', '100', '50', '0', 'floored'],
]);

test('higher is better: zero and negative targets follow the signed rule or are not scorable', function (string $rule, string $target, KpiDataStatus $status, ?string $score, string $note): void {
    $outcome = (new RatioToTargetCalculator)->calculate(Exact::parse('10'), Exact::parse($target), ScoringFixtures::ratioParameters(['zero_target_rule' => $rule]));

    expect($outcome->status)->toBe($status)
        ->and($outcome->score === null ? null : Exact::fraction($outcome->score))->toBe($score)
        ->and($outcome->note)->toBe($note);
})->with([
    'zero target → N/A' => ['not_applicable', '0', KpiDataStatus::RuleNotApplicable, null, 'zero_target_not_applicable'],
    'zero target → cap' => ['score_cap', '0', KpiDataStatus::Scored, '120', 'zero_target_score_cap'],
    'zero target → floor' => ['score_floor', '0', KpiDataStatus::Scored, '0', 'zero_target_score_floor'],
    'negative target' => ['score_cap', '-1', KpiDataStatus::InvalidInput, null, 'negative_target'],
]);

test('lower is better: target ÷ actual × 100 with explicit zero and negative rules', function (string $actual, string $target, array $rules, KpiDataStatus $status, ?string $score, ?string $note): void {
    $outcome = (new RatioToTargetCalculator(inverse: true))->calculate(
        Exact::parse($actual),
        Exact::parse($target),
        ScoringFixtures::ratioParameters(['zero_actual_rule' => 'score_cap', ...$rules]),
    );

    expect($outcome->status)->toBe($status)
        ->and($outcome->score === null ? null : Exact::fraction($outcome->score))->toBe($score)
        ->and($outcome->note)->toBe($note);
})->with([
    'better than target' => ['4', '5', [], KpiDataStatus::Scored, '120', 'capped'],
    'worse than target' => ['8', '5', [], KpiDataStatus::Scored, '125/2', null],
    'on target' => ['5', '5', [], KpiDataStatus::Scored, '100', null],
    'zero actual → cap' => ['0', '5', [], KpiDataStatus::Scored, '120', 'zero_actual_score_cap'],
    'zero actual → N/A' => ['0', '5', ['zero_actual_rule' => 'not_applicable'], KpiDataStatus::RuleNotApplicable, null, 'zero_actual_not_applicable'],
    'zero actual and zero target uses the actual rule' => ['0', '0', [], KpiDataStatus::Scored, '120', 'zero_actual_score_cap'],
    'zero target, positive actual → target rule' => ['3', '0', ['zero_target_rule' => 'score_floor'], KpiDataStatus::Scored, '0', 'zero_target_score_floor'],
    'negative actual' => ['-1', '5', [], KpiDataStatus::InvalidInput, null, 'negative_actual'],
    'negative target' => ['1', '-5', [], KpiDataStatus::InvalidInput, null, 'negative_target'],
]);

test('range bands are half-open and fall back to the out-of-band score', function (string $measured, string $score, string $note): void {
    $parameters = [
        'bands' => [
            ['min' => '-10', 'max' => '0', 'score' => '40'],
            ['min' => '0', 'max' => '95', 'score' => '70'],
            ['min' => '95', 'max' => '100.0001', 'score' => '100'],
        ],
        'out_of_band_score' => '0',
    ];

    $outcome = (new RangeBandCalculator)->calculate(Exact::parse($measured), null, $parameters);

    expect($outcome->status)->toBe(KpiDataStatus::Scored)
        ->and(Exact::fraction($outcome->score))->toBe($score)
        ->and($outcome->note)->toBe($note);
})->with([
    'below every band' => ['-10.0001', '0', 'out_of_band'],
    'first band minimum' => ['-10', '40', 'band_1'],
    'boundary belongs to the next band' => ['0', '70', 'band_2'],
    'just below a boundary' => ['94.9999', '70', 'band_2'],
    'exactly at a boundary' => ['95', '100', 'band_3'],
    'last band before its exclusive max' => ['100', '100', 'band_3'],
    'at the exclusive max' => ['100.0001', '0', 'out_of_band'],
]);

test('binary accepts exactly 1 or 0 and refuses anything else', function (string $measured, KpiDataStatus $status, ?string $score): void {
    $outcome = (new BinaryCalculator)->calculate(Exact::parse($measured), null, ['achieved_score' => '100', 'not_achieved_score' => '0']);

    expect($outcome->status)->toBe($status)
        ->and($outcome->score === null ? null : Exact::fraction($outcome->score))->toBe($score);
})->with([
    'yes' => ['1', KpiDataStatus::Scored, '100'],
    'no' => ['0', KpiDataStatus::Scored, '0'],
    'sum of two windows' => ['2', KpiDataStatus::InvalidInput, null],
    'average of mixed windows' => ['0.5', KpiDataStatus::InvalidInput, null],
]);

test('milestone scores the reached stage and refuses a stage that does not exist', function (string $measured, KpiDataStatus $status, ?string $score): void {
    $parameters = ['stages' => [
        ['label' => 'Rencana', 'score' => '25'],
        ['label' => 'Pelaksanaan', 'score' => '60'],
        ['label' => 'Selesai', 'score' => '100'],
    ]];

    $outcome = (new MilestoneStageCalculator)->calculate(Exact::parse($measured), null, $parameters);

    expect($outcome->status)->toBe($status)
        ->and($outcome->score === null ? null : Exact::fraction($outcome->score))->toBe($score);
})->with([
    'first stage' => ['1', KpiDataStatus::Scored, '25'],
    'last stage' => ['3', KpiDataStatus::Scored, '100'],
    'stage zero' => ['0', KpiDataStatus::InvalidInput, null],
    'beyond the last stage' => ['4', KpiDataStatus::InvalidInput, null],
    'fractional stage (average)' => ['2.5', KpiDataStatus::InvalidInput, null],
]);

test('a calculator refuses unusable stored parameters instead of guessing', function (): void {
    expect(fn () => (new RatioToTargetCalculator)->calculate(Exact::parse('1'), Exact::parse('1'), ['score_floor' => '0']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new RatioToTargetCalculator)->calculate(Exact::parse('1'), Exact::parse('0'), ScoringFixtures::ratioParameters(['zero_target_rule' => 'guess'])))
        ->toThrow(InvalidArgumentException::class);
});
