<?php

declare(strict_types=1);

use App\Modules\PeriodAssignment\Domain\DeadlineStage;
use App\Modules\Target\Domain\TargetValueRules;

test('a valid value is stored as a canonical four-decimal string', function (string $raw, string $stored): void {
    $rules = TargetValueRules::for('percent', 'percentage', null, null, null, null);

    expect($rules->parse($raw, 'percent'))->toBe($stored);
})->with([
    ['95', '95.0000'],
    [' 95.5 ', '95.5000'],
    ['0.1234', '0.1234'],
    ['-2.5', '-2.5000'],
    ['-0', '0.0000'],
    ['999999999999.9999', '999999999999.9999'],
]);

test('values that are not plain decimals are rejected', function (string $raw): void {
    $rules = TargetValueRules::for('percent', 'percentage', null, null, null, null);

    expect(fn () => $rules->parse($raw, 'percent'))->toThrow(InvalidArgumentException::class);
})->with(['', 'abc', '1,5', '1.234,5', '1e3', '12.', '.5', '+5', '1 000', '1234567890123', '0.12345']);

test('the unit must be the unit of the KPI', function (): void {
    $rules = TargetValueRules::for('percent', 'percentage', null, null, null, null);

    expect(fn () => $rules->parse('95', 'pieces'))->toThrow(InvalidArgumentException::class, 'Satuan target (pieces) tidak sesuai dengan satuan KPI (percent).')
        ->and(fn () => TargetValueRules::for(null, 'decimal', null, null, null, null)->parse('1', 'percent'))->toThrow(InvalidArgumentException::class);
});

test('the number of decimals follows the definition and the value type', function (?int $decimalPlaces, string $valueType, string $raw, bool $accepted): void {
    $rules = TargetValueRules::for('count', $valueType, $decimalPlaces, null, null, null);
    $parse = fn () => $rules->parse($raw, 'count');

    $accepted ? expect($parse())->toBeString() : expect($parse)->toThrow(InvalidArgumentException::class);
})->with([
    'two decimals allowed' => [2, 'decimal', '10.25', true],
    'third decimal rejected' => [2, 'decimal', '10.255', false],
    'trailing zero is still a third decimal' => [2, 'decimal', '10.250', false],
    'zero decimals' => [0, 'decimal', '10.0', false],
    'integer type ignores a wider definition' => [4, 'integer', '10.5', false],
    'integer type accepts whole numbers' => [null, 'integer', '10', true],
    'no rule means four decimals' => [null, 'decimal', '10.1234', true],
]);

test('the valid input range of the definition is enforced, inclusive', function (string $raw, bool $accepted): void {
    $rules = TargetValueRules::for('percent', 'percentage', 2, '0', '100', null);
    $parse = fn () => $rules->parse($raw, 'percent');

    $accepted ? expect($parse())->toBeString() : expect($parse)->toThrow(InvalidArgumentException::class);
})->with([
    ['0', true],
    ['100', true],
    ['100.01', false],
    ['-0.01', false],
]);

test('boolean and milestone KPIs accept only their own values', function (): void {
    $binary = TargetValueRules::for('yes_no', 'boolean', null, null, null, null);
    $milestone = TargetValueRules::for('stage', 'milestone', null, null, null, ['stages' => [['label' => 'A', 'score' => '50'], ['label' => 'B', 'score' => '100']]]);

    expect($binary->parse('1', 'yes_no'))->toBe('1.0000')
        ->and($binary->parse('0', 'yes_no'))->toBe('0.0000')
        ->and(fn () => $binary->parse('2', 'yes_no'))->toThrow(InvalidArgumentException::class)
        ->and($milestone->parse('2', 'stage'))->toBe('2.0000')
        ->and(fn () => $milestone->parse('3', 'stage'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $milestone->parse('0', 'stage'))->toThrow(InvalidArgumentException::class);
});

test('stored values are displayed without trailing zeros', function (): void {
    expect(TargetValueRules::display('95.5000'))->toBe('95.5')
        ->and(TargetValueRules::display('95.0000'))->toBe('95')
        ->and(TargetValueRules::display('-0.0500'))->toBe('-0.05')
        ->and(TargetValueRules::display(null))->toBe('—');
});

test('stage deadlines must follow the order of the assessment flow', function (): void {
    expect(DeadlineStage::orderProblems(['target_setting' => '2026-11-05', 'review' => '2026-11-05', 'final_approval' => '2026-12-01']))->toBe([])
        ->and(DeadlineStage::orderProblems(['final_approval' => '2026-11-01', 'target_setting' => '2026-11-05']))->toHaveCount(1);
});
