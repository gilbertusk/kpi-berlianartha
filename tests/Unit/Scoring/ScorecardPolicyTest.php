<?php

declare(strict_types=1);

use App\Modules\Scoring\Domain\RoundingRule;
use App\Modules\Scoring\Domain\ScorecardPolicy;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Tests\Support\ScoringFixtures as F;

test('a complete policy is accepted and keeps every rule it states', function (): void {
    $policy = F::policy();

    expect($policy->reference())->toBe('test-only@1')
        ->and($policy->toArray())->toBe([
            'key' => 'test-only',
            'version' => 1,
            'signoff_reference' => 'TEST-ONLY — bukan keputusan bisnis',
            'effective_from' => '2026-10-01',
            'effective_until' => null,
            'late_data_rule' => 'include',
            'not_applicable_weight_rule' => 'redistribute_proportionally',
            'contribution_rounding' => ['mode' => 'half_up', 'scale' => 4],
            'final_rounding' => ['mode' => 'half_up', 'scale' => 2],
            'rating_bands' => [
                ['key' => 'r1', 'label' => 'Band uji 1', 'min' => null, 'max' => '60'],
                ['key' => 'r2', 'label' => 'Band uji 2', 'min' => '60', 'max' => '80'],
                ['key' => 'r3', 'label' => 'Band uji 3', 'min' => '80', 'max' => '100'],
                ['key' => 'r4', 'label' => 'Band uji 4', 'min' => '100', 'max' => null],
            ],
        ])
        ->and($policy->hash())->toBe(F::policy()->hash());
});

test('a policy has no defaults: every rule must be stated', function (string $field): void {
    $config = F::policyConfig();
    unset($config[$field]);

    expect(fn () => ScorecardPolicy::fromArray($config))->toThrow(InvalidArgumentException::class, "Field wajib tidak ada: {$field}.");
})->with(['key', 'version', 'signoff_reference', 'effective_from', 'late_data_rule', 'not_applicable_weight_rule', 'contribution_rounding', 'final_rounding', 'rating_bands']);

test('invalid policy values are rejected with a reason', function (array $overrides, string $message): void {
    expect(fn () => F::policy($overrides))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown field' => [['rating_rule' => 'x'], 'Field tidak dikenal: rating_rule.'],
    'empty sign-off' => [['signoff_reference' => '  '], 'signoff_reference wajib diisi'],
    'version zero' => [['version' => 0], 'version harus bilangan bulat'],
    'bad key' => [['key' => 'Policy A'], 'key harus'],
    'impossible date' => [['effective_from' => '2026-02-30'], 'effective_from harus tanggal'],
    'end before start' => [['effective_until' => '2026-09-30'], 'effective_until harus tanggal'],
    'unknown late rule' => [['late_data_rule' => 'sometimes'], 'late_data_rule tidak dikenal.'],
    'unknown N/A rule' => [['not_applicable_weight_rule' => 'zero'], 'not_applicable_weight_rule tidak dikenal.'],
    'rounding without scale' => [['final_rounding' => ['mode' => 'half_up']], 'final_rounding: harus berisi tepat mode dan scale.'],
    'rounding scale 5' => [['contribution_rounding' => ['mode' => 'half_up', 'scale' => 5]], 'contribution_rounding: Jumlah desimal'],
    'no bands' => [['rating_bands' => []], 'rating_bands wajib berisi'],
    'gap between bands' => [['rating_bands' => [['key' => 'a', 'label' => 'A', 'min' => null, 'max' => '50'], ['key' => 'b', 'label' => 'B', 'min' => '51', 'max' => null]]], 'Band 2: min harus sama dengan max band sebelumnya'],
    'overlapping bands' => [['rating_bands' => [['key' => 'a', 'label' => 'A', 'min' => null, 'max' => '50'], ['key' => 'b', 'label' => 'B', 'min' => '49', 'max' => null]]], 'Band 2: min harus sama dengan max band sebelumnya'],
    'open band in the middle' => [['rating_bands' => [['key' => 'a', 'label' => 'A', 'min' => null, 'max' => null], ['key' => 'b', 'label' => 'B', 'min' => '50', 'max' => null]]], 'hanya band terakhir yang boleh tanpa max'],
    'empty band' => [['rating_bands' => [['key' => 'a', 'label' => 'A', 'min' => '50', 'max' => '50']]], 'Band 1: min harus lebih kecil dari max.'],
    'duplicate band key' => [['rating_bands' => [['key' => 'a', 'label' => 'A', 'min' => null, 'max' => '50'], ['key' => 'a', 'label' => 'B', 'min' => '50', 'max' => null]]], 'Band 2: key wajib unik'],
    'boundary with float noise' => [['rating_bands' => [['key' => 'a', 'label' => 'A', 'min' => null, 'max' => '0.12345']]], 'Band 1: min dan max harus angka desimal'],
]);

test('effective dates are half-open and overlap is detected', function (): void {
    $first = F::policy(['effective_until' => '2026-12-01']);
    $second = F::policy(['version' => 2, 'effective_from' => '2026-12-01']);
    $overlapping = F::policy(['version' => 3, 'effective_from' => '2026-11-30']);

    expect($first->isInForceOn('2026-11-30'))->toBeTrue()
        ->and($first->isInForceOn('2026-12-01'))->toBeFalse()
        ->and($second->isInForceOn('2026-12-01'))->toBeTrue()
        ->and($first->overlaps($second))->toBeFalse()
        ->and($first->overlaps($overlapping))->toBeTrue();
});

test('rounding modes behave exactly at, below, and above the half', function (string $mode, string $value, string $expected): void {
    $exact = BigRational::of($value);

    expect(RoundingRule::of($mode, 2)->apply($exact)->toString())->toBe($expected);
})->with([
    ['half_up', '1.005', '1.01'],
    ['half_up', '1.0049999', '1.00'],
    ['half_up', '1.0050001', '1.01'],
    ['half_even', '1.005', '1.00'],
    ['half_even', '1.015', '1.02'],
    ['half_even', '1.0050001', '1.01'],
    ['down', '1.0099999', '1.00'],
    ['down', '1.01', '1.01'],
]);

test('rounding of a non-terminating value is exact, not double rounded', function (): void {
    // 2.005 + 1/30000 is above the half; truncating it to 10 digits first would hide that.
    $value = BigRational::of('2.005')->plus(BigRational::ofFraction(1, 30_000_000_000));

    expect(RoundingRule::of('half_even', 2)->apply($value)->toString())->toBe('2.01')
        ->and(RoundingRule::of('half_even', 2)->apply(BigRational::of('2.005'))->toString())->toBe('2.00');
});

test('rating bands treat each boundary as the start of the next band', function (): void {
    $policy = F::policy();

    expect($policy->ratingFor(BigDecimal::of('59.99'))?->key)->toBe('r1')
        ->and($policy->ratingFor(BigDecimal::of('60.00'))?->key)->toBe('r2')
        ->and($policy->ratingFor(BigDecimal::of('-1'))?->key)->toBe('r1')
        ->and($policy->ratingFor(BigDecimal::of('1000'))?->key)->toBe('r4');
});

test('closing a version with an end date does not change its rules; changing a rule does', function (): void {
    $open = F::policy();

    expect(F::policy(['effective_until' => '2026-12-01'])->hash())->toBe($open->hash())
        ->and(F::policy(['final_rounding' => ['mode' => 'down', 'scale' => 2]])->hash())->not->toBe($open->hash())
        ->and(F::policy(['signoff_reference' => 'TEST-ONLY-2'])->hash())->not->toBe($open->hash());
});
