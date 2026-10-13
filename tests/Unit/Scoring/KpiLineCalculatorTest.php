<?php

declare(strict_types=1);

use App\Modules\Scoring\Domain\Exact;
use App\Modules\Scoring\Domain\KpiDataStatus;
use App\Modules\Scoring\Domain\KpiLineCalculator;
use App\Modules\Scoring\Domain\Strategy\CalculatorRegistry;
use Tests\Support\ScoringFixtures as F;

beforeEach(function (): void {
    $this->lines = new KpiLineCalculator(new CalculatorRegistry);
});

test('missing data follows the KPI rule and is never zero by default', function (string $rule, KpiDataStatus $status, ?string $score): void {
    $result = $this->lines->calculate(F::line(['parameters' => F::ratioParameters(['missing_data_rule' => $rule])]), 'include');

    expect($result->status)->toBe($status)
        ->and($result->score?->toString())->toBe($score)
        ->and($result->measured)->toBeNull();
})->with([
    'block until provided' => ['block_until_provided', KpiDataStatus::MissingBlocked, null],
    'mark N/A' => ['mark_not_applicable', KpiDataStatus::MissingNotApplicable, null],
    'score zero only when the KPI says so' => ['score_zero', KpiDataStatus::MissingScoredZero, '0.00'],
]);

test('an unknown missing-data rule is not scorable', function (): void {
    $result = $this->lines->calculate(F::line(['parameters' => F::ratioParameters(['missing_data_rule' => 'guess'])]), 'include');

    expect($result->status)->toBe(KpiDataStatus::InvalidInput)->and($result->note)->toBe('invalid_parameters');
});

test('an N/A entry is N/A, not zero, and is left out of an average', function (): void {
    $onlyNa = $this->lines->calculate(F::line(['entries' => [F::entry(null)]]), 'include');

    expect($onlyNa->status)->toBe(KpiDataStatus::NotApplicable)
        ->and($onlyNa->score)->toBeNull();

    $mixed = $this->lines->calculate(F::line(['entries' => [
        F::entry('90', '2026-11-01'),
        F::entry(null, '2026-11-02'),
        F::entry('100', '2026-11-03'),
    ]]), 'include');

    // (90 + 100) ÷ 2 = 95; the N/A day is not a zero.
    expect($mixed->status)->toBe(KpiDataStatus::Scored)
        ->and(Exact::describe($mixed->measured))->toBe('95')
        ->and($mixed->score->toString())->toBe('95.00')
        ->and(array_column($mixed->trace, 'decision'))->toBe(['used', 'not_applicable', 'used']);
});

test('zero is a value and scores as zero achievement', function (): void {
    $result = $this->lines->calculate(F::line(['entries' => [F::entry('0')]]), 'include');

    expect($result->status)->toBe(KpiDataStatus::Scored)
        ->and(Exact::describe($result->achievement))->toBe('0')
        ->and($result->score->toString())->toBe('0.00');
});

test('any entry that is not verified makes the line wait instead of scoring partial data', function (string $status): void {
    $result = $this->lines->calculate(F::line(['entries' => [F::entry('90', '2026-11-01'), F::entry('80', '2026-11-02', status: $status)]]), 'include');

    expect($result->status)->toBe(KpiDataStatus::PendingVerification)
        ->and($result->score)->toBeNull()
        ->and($result->status->blocks())->toBeTrue();
})->with(['draft', 'submitted', 'rejected']);

test('late entries are counted or excluded exactly as the policy says', function (): void {
    $line = F::line(['entries' => [F::entry('80', '2026-11-01'), F::entry('100', '2026-11-02', late: true)]]);

    $included = $this->lines->calculate($line, 'include');
    $excluded = $this->lines->calculate($line, 'exclude');

    expect(Exact::describe($included->measured))->toBe('90')
        ->and(Exact::describe($excluded->measured))->toBe('80')
        ->and(array_column($excluded->trace, 'decision'))->toBe(['used', 'excluded_late']);

    // Excluding the only entry leaves the KPI missing, under its own rule.
    $onlyLate = $this->lines->calculate(F::line(['entries' => [F::entry('100', late: true)]]), 'exclude');
    expect($onlyLate->status)->toBe(KpiDataStatus::MissingBlocked);
});

test('a late draft does not hold the line when late data is excluded', function (): void {
    $result = $this->lines->calculate(F::line(['entries' => [F::entry('80', '2026-11-01'), F::entry('100', '2026-11-02', status: 'draft', late: true)]]), 'exclude');

    expect($result->status)->toBe(KpiDataStatus::Scored)->and(Exact::describe($result->measured))->toBe('80');
});

test('the signed aggregation gives the measured value regardless of entry order', function (string $aggregation, string $measured): void {
    $entries = [F::entry('7', '2026-11-03'), F::entry('3', '2026-11-01'), F::entry('5', '2026-11-02')];

    $forward = $this->lines->calculate(F::line(['aggregation' => $aggregation, 'entries' => $entries]), 'include');
    $reversed = $this->lines->calculate(F::line(['aggregation' => $aggregation, 'entries' => array_reverse($entries)]), 'include');

    expect(Exact::describe($forward->measured))->toBe($measured)
        ->and($reversed->toArray())->toBe($forward->toArray());
})->with([
    ['sum', '15'],
    ['average', '5'],
    ['last_value', '7'],
    ['minimum', '3'],
    ['maximum', '7'],
    ['count', '3'],
]);

test('an average that does not terminate is kept exact until the KPI rounding', function (): void {
    $result = $this->lines->calculate(F::line(['entries' => [F::entry('100', '2026-11-01'), F::entry('100', '2026-11-02'), F::entry('101', '2026-11-03')]]), 'include');

    // 301 ÷ 3 = 100.333… → 100.33 (half up, 2 decimals).
    expect(Exact::fraction($result->measured))->toBe('301/3')
        ->and(Exact::describe($result->measured))->toBe('≈100.33333333')
        ->and($result->score->toString())->toBe('100.33');
});

test('ratio of sums is reported as unsupported rather than approximated', function (): void {
    $result = $this->lines->calculate(F::line(['aggregation' => 'ratio_of_sums', 'entries' => [F::entry('5')]]), 'include');

    expect($result->status)->toBe(KpiDataStatus::Unsupported)->and($result->note)->toBe('aggregation_not_supported');
});

test('a strategy without a registered calculation is unsupported', function (): void {
    $result = $this->lines->calculate(F::line(['strategyKey' => 'ratio_to_target', 'strategyVersion' => 2, 'entries' => [F::entry('5')]]), 'include');

    expect($result->status)->toBe(KpiDataStatus::Unsupported)->and($result->note)->toBe('strategy_not_supported');
});

test('a ratio strategy without a final target waits for the target', function (): void {
    $result = $this->lines->calculate(F::line(['targetValue' => null, 'entries' => [F::entry('5')]]), 'include');

    expect($result->status)->toBe(KpiDataStatus::TargetMissing)->and($result->status->blocks())->toBeTrue();
});

test('strategies that do not use a target score without one', function (): void {
    $result = $this->lines->calculate(F::line([
        'targetValue' => null,
        'strategyKey' => 'binary',
        'aggregation' => 'last_value',
        'parameters' => ['missing_data_rule' => 'block_until_provided', 'rounding_mode' => 'half_up', 'rounding_scale' => 0, 'achieved_score' => '100', 'not_achieved_score' => '0'],
        'entries' => [F::entry('0', '2026-11-01'), F::entry('1', '2026-11-02')],
    ]), 'include');

    expect($result->status)->toBe(KpiDataStatus::Scored)->and($result->score->toString())->toBe('100');
});

test('a stored value outside the definition range is not scored', function (): void {
    $result = $this->lines->calculate(F::line(['maxValidValue' => '100', 'entries' => [F::entry('100.0001')]]), 'include');

    expect($result->status)->toBe(KpiDataStatus::InvalidInput)->and($result->note)->toBe('value_out_of_range');

    $atLimit = $this->lines->calculate(F::line(['minValidValue' => '0', 'maxValidValue' => '100', 'entries' => [F::entry('100')]]), 'include');
    expect($atLimit->status)->toBe(KpiDataStatus::Scored);
});

test('unusable rounding parameters are not scorable', function (array $parameters): void {
    $result = $this->lines->calculate(F::line(['parameters' => F::ratioParameters($parameters), 'entries' => [F::entry('5')]]), 'include');

    expect($result->status)->toBe(KpiDataStatus::InvalidInput)->and($result->note)->toBe('invalid_parameters');
})->with([
    'unknown mode' => [['rounding_mode' => 'ceiling']],
    'scale above 4' => [['rounding_scale' => 5]],
    'missing cap' => [['score_cap' => null]],
]);

test('a signed bound or score finer than the KPI rounding is refused instead of being rounded past', function (array $parameters): void {
    // Floor 37.45 with whole-number rounding: 37.46 would round to 37, below the floor.
    $result = $this->lines->calculate(F::line(['parameters' => F::ratioParameters($parameters), 'entries' => [F::entry('37.46')]]), 'include');

    expect($result->status)->toBe(KpiDataStatus::InvalidInput)->and($result->note)->toBe('score_finer_than_rounding');
})->with([
    'floor' => [['score_floor' => '37.45', 'rounding_scale' => 0]],
    'cap' => [['score_cap' => '110.5', 'rounding_scale' => 0]],
]);

test('a KPI scored against a target waits for the target instead of following the missing-data rule', function (string $rule): void {
    $result = $this->lines->calculate(F::line(['targetValue' => null, 'entries' => [], 'parameters' => F::ratioParameters(['missing_data_rule' => $rule])]), 'include');

    expect($result->status)->toBe(KpiDataStatus::TargetMissing)
        ->and($result->score)->toBeNull();
})->with(['score_zero', 'mark_not_applicable', 'block_until_provided']);

test('a line whose entries were all excluded as late says so in its note', function (): void {
    $line = F::line(['entries' => [F::entry('90', '2026-11-02', late: true)], 'parameters' => F::ratioParameters(['missing_data_rule' => 'score_zero'])]);

    expect($this->lines->calculate($line, 'exclude')->note)->toBe('missing_score_zero_late_excluded')
        ->and($this->lines->calculate(F::line(['entries' => [], 'parameters' => F::ratioParameters(['missing_data_rule' => 'score_zero'])]), 'exclude')->note)->toBe('missing_score_zero');
});
