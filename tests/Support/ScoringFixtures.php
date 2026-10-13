<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Scoring\Domain\EntryInput;
use App\Modules\Scoring\Domain\KpiLineCalculator;
use App\Modules\Scoring\Domain\KpiLineInput;
use App\Modules\Scoring\Domain\ScorecardCalculator;
use App\Modules\Scoring\Domain\ScorecardInput;
use App\Modules\Scoring\Domain\ScorecardPolicy;
use App\Modules\Scoring\Domain\Strategy\CalculatorRegistry;

/**
 * Synthetic scoring inputs for tests. THE NUMBERS ARE NOT BUSINESS RULES:
 * the policy below is a test fixture labelled TEST-ONLY, and DEC-004 is
 * still open. Each helper takes overrides so a test states the values it
 * depends on.
 */
final class ScoringFixtures
{
    public static function engine(): ScorecardCalculator
    {
        return new ScorecardCalculator(new KpiLineCalculator(new CalculatorRegistry));
    }

    /**
     * @return array<string, mixed>
     */
    public static function policyConfig(array $overrides = []): array
    {
        return [
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
            ...$overrides,
        ];
    }

    public static function policy(array $overrides = []): ScorecardPolicy
    {
        return ScorecardPolicy::fromArray(self::policyConfig($overrides));
    }

    /**
     * @return array<string, mixed>
     */
    public static function ratioParameters(array $overrides = []): array
    {
        return [
            'missing_data_rule' => 'block_until_provided',
            'rounding_mode' => 'half_up',
            'rounding_scale' => 2,
            'score_floor' => '0',
            'score_cap' => '120',
            'zero_target_rule' => 'not_applicable',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides  KpiLineInput constructor arguments by name
     */
    public static function line(array $overrides = []): KpiLineInput
    {
        $values = [
            'kpiCode' => 'K-1',
            'kpiName' => 'KPI uji',
            'definitionVersionId' => 1,
            'component' => null,
            'weight' => '100.0000',
            'isMandatory' => true,
            'targetReference' => 'target-1',
            'targetValue' => '100.0000',
            'strategyKey' => 'ratio_to_target',
            'strategyVersion' => 1,
            'parameters' => self::ratioParameters(),
            'aggregation' => 'average',
            'minValidValue' => null,
            'maxValidValue' => null,
            'entries' => [],
            ...$overrides,
        ];

        return new KpiLineInput(...$values);
    }

    public static function entry(?string $value, string $start = '2026-11-01', ?string $end = null, string $status = 'verified', bool $late = false, int $version = 1): EntryInput
    {
        return new EntryInput(
            reference: "actual-{$start}",
            version: $version,
            windowStart: $start,
            windowEnd: $end ?? $start,
            status: $status,
            kind: $value === null ? EntryInput::KIND_NOT_APPLICABLE : EntryInput::KIND_VALUE,
            value: $value,
            isLate: $late,
        );
    }

    /**
     * @param  list<KpiLineInput>  $lines
     */
    public static function card(array $lines): ScorecardInput
    {
        return new ScorecardInput('subject-1', 'employee', 'T-00001', 'T-UJI@1', $lines);
    }
}
