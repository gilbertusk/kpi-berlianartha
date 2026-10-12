<?php

declare(strict_types=1);

use App\Modules\KpiGovernance\Domain\DefinitionContent;
use App\Modules\KpiGovernance\Domain\ItemApplicability;
use App\Modules\KpiGovernance\Domain\Scoring\ScoreStrategyRegistry;
use App\Modules\KpiGovernance\Domain\TemplateComposition;
use App\Modules\KpiGovernance\Domain\Weight;
use Database\Factories\KpiDefinitionFactory;

test('weights are exact decimals with at most four places', function (): void {
    expect(Weight::fromString('12.5')->units)->toBe(125_000)
        ->and(Weight::fromString('100')->format())->toBe('100')
        ->and(Weight::fromString('33.3334')->toDecimalString())->toBe('33.3334')
        ->and(fn () => Weight::fromString('0'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Weight::fromString('100.0001'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Weight::fromString('1.23456'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Weight::fromString('1e2'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Weight::fromString('-5'))->toThrow(InvalidArgumentException::class);
});

test('a template publishes only when the weights total exactly 100 percent', function (array $weights, bool $valid): void {
    $items = array_map(fn (string $weight, int $index): array => [
        'definition_id' => $index + 1,
        'weight_units' => Weight::fromString($weight)->units,
    ], $weights, array_keys($weights));

    expect(TemplateComposition::publishErrors($items) === [])->toBe($valid);
})->with([
    'PRD staff draft 50/30/10/10' => [['50', '30', '10', '10'], true],
    'thirds rounded down' => [['33.3333', '33.3333', '33.3333'], false],
    'thirds balanced' => [['33.3334', '33.3333', '33.3333'], true],
    'over 100' => [['60', '50'], false],
    'under 100' => [['99.9999'], false],
]);

test('the same KPI may not appear twice and an empty template is rejected', function (): void {
    expect(TemplateComposition::publishErrors([]))->not->toBe([])
        ->and(TemplateComposition::publishErrors([
            ['definition_id' => 1, 'weight_units' => Weight::fromString('50')->units],
            ['definition_id' => 1, 'weight_units' => Weight::fromString('50')->units],
        ]))->toContain('Satu KPI hanya boleh muncul sekali dalam satu template.');
});

test('only whitelisted strategies exist and none takes free-text formulas', function (): void {
    $registry = new ScoreStrategyRegistry;

    expect(array_keys($registry->current()))->toEqualCanonicalizing([
        'ratio_to_target', 'inverse_ratio_to_target', 'range_band', 'binary', 'milestone_stage',
    ])
        ->and($registry->find('eval', 1))->toBeNull()
        ->and($registry->find('ratio_to_target', 99))->toBeNull();
});

test('strategies require explicit rules instead of silent defaults', function (string $key, array $parameters, bool $valid): void {
    $strategy = (new ScoreStrategyRegistry)->find($key, 1);

    expect($strategy)->not->toBeNull()
        ->and($strategy?->validate($parameters) === [])->toBe($valid);
})->with([
    'ratio without rules' => ['ratio_to_target', [], false],
    'ratio complete' => ['ratio_to_target', ['missing_data_rule' => 'score_zero', 'rounding_mode' => 'half_up', 'rounding_scale' => 2, 'score_floor' => '0', 'score_cap' => '120', 'zero_target_rule' => 'not_applicable'], true],
    'ratio float strings rejected' => ['ratio_to_target', ['missing_data_rule' => 'score_zero', 'rounding_mode' => 'half_up', 'rounding_scale' => 2, 'score_floor' => '0', 'score_cap' => '1.2e2', 'zero_target_rule' => 'not_applicable'], false],
    'ratio floor above cap' => ['ratio_to_target', ['missing_data_rule' => 'score_zero', 'rounding_mode' => 'half_up', 'rounding_scale' => 2, 'score_floor' => '130', 'score_cap' => '120', 'zero_target_rule' => 'not_applicable'], false],
    'inverse needs zero-actual rule' => ['inverse_ratio_to_target', ['missing_data_rule' => 'score_zero', 'rounding_mode' => 'half_up', 'rounding_scale' => 2, 'score_floor' => '0', 'score_cap' => '120', 'zero_target_rule' => 'not_applicable'], false],
    'range bands ordered' => ['range_band', ['missing_data_rule' => 'score_zero', 'rounding_mode' => 'half_up', 'rounding_scale' => 0, 'bands' => [['min' => '0', 'max' => '90', 'score' => '50'], ['min' => '90', 'max' => '110', 'score' => '100']], 'out_of_band_score' => '0'], true],
    'range bands overlapping' => ['range_band', ['missing_data_rule' => 'score_zero', 'rounding_mode' => 'half_up', 'rounding_scale' => 0, 'bands' => [['min' => '0', 'max' => '95', 'score' => '50'], ['min' => '90', 'max' => '110', 'score' => '100']], 'out_of_band_score' => '0'], false],
    'binary complete' => ['binary', ['missing_data_rule' => 'mark_not_applicable', 'rounding_mode' => 'down', 'rounding_scale' => 0, 'achieved_score' => '100', 'not_achieved_score' => '0'], true],
    'milestone decreasing' => ['milestone_stage', ['missing_data_rule' => 'score_zero', 'rounding_mode' => 'half_even', 'rounding_scale' => 0, 'stages' => [['label' => 'A', 'score' => '50'], ['label' => 'B', 'score' => '40']]], false],
]);

test('a definition is publishable only with owner, source, formula, target rule, approver, and sign-off', function (string $missingField): void {
    $registry = new ScoreStrategyRegistry;
    $content = KpiDefinitionFactory::completeContent();

    expect(DefinitionContent::fromArray($content)->publishErrors($registry))->toBe([]);

    $content[$missingField] = '';

    expect(DefinitionContent::fromArray($content)->publishErrors($registry))->not->toBe([]);
})->with(['process_owner', 'data_owner', 'data_source', 'formula_description', 'target_rule', 'approval_chain', 'signoff_reference', 'score_strategy', 'effective_from']);

test('a strategy must match the KPI direction', function (): void {
    $content = [...KpiDefinitionFactory::completeContent(), 'direction' => 'lower_is_better'];

    expect(DefinitionContent::fromArray($content)->publishErrors(new ScoreStrategyRegistry))
        ->toContain('Strategi skor tidak sesuai dengan arah KPI.');
});

test('vocabulary values outside the allowlist are rejected even for drafts', function (): void {
    $errors = DefinitionContent::fromArray(['name' => 'X', 'unit' => 'bananas', 'frequency' => 'hourly'])
        ->draftErrors(new ScoreStrategyRegistry);

    expect($errors)->toContain('Satuan tidak dikenal.')
        ->toContain('Frekuensi tidak dikenal.');
});

test('item applicability is a structured position list, never an expression', function (): void {
    expect(ItemApplicability::fromList('Operator, operator , Leader Shift')?->positions)->toBe(['Operator', 'Leader Shift'])
        ->and(ItemApplicability::fromList(''))->toBeNull()
        ->and(ItemApplicability::fromList('Operator')?->appliesTo('OPERATOR'))->toBeTrue()
        ->and(fn () => ItemApplicability::fromArray(['expression' => 'role == "x"']))->toThrow(InvalidArgumentException::class);
});
