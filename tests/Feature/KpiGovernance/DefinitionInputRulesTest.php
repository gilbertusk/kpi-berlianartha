<?php

declare(strict_types=1);

use App\Modules\KpiGovernance\Application\KpiDefinitionService;
use App\Modules\KpiGovernance\Application\VersionComparison;
use Database\Factories\KpiDefinitionFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;

/*
 * Step 07 adds the input rules of KPI_DEFINITION_TEMPLATE.md §D to the
 * definition content: number of decimals and the valid input range.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->kpi = new KpiFixtures;
    $this->definitions = app(KpiDefinitionService::class);
});

test('input rules are optional, stored canonically, and part of the version comparison', function (): void {
    $plain = $this->kpi->publishedDefinition('IR-PLAIN');
    $draft = $this->kpi->draftDefinition('IR-RULES', 'PROD-WOVEN', ['decimal_places' => '2', 'min_valid_value' => '0.50', 'max_valid_value' => ' 100 ']);

    expect($plain->decimal_places)->toBeNull()
        ->and($plain->min_valid_value)->toBeNull()
        ->and($draft->fresh()?->decimal_places)->toBe(2)
        ->and($draft->fresh()?->min_valid_value)->toBe('0.5')
        ->and($draft->fresh()?->max_valid_value)->toBe('100');

    $published = $this->kpi->submitAndPublish($draft);
    $next = $this->definitions->startNewVersion($this->kpi->authorActor(), $published->kpi_definition_id, 'Presisi dinaikkan');
    $next = $this->definitions->updateDraft($this->kpi->authorActor(), $next->id, $next->lock_version, [...$next->content()->toArray(), 'decimal_places' => 3]);

    expect(collect(app(VersionComparison::class)->definitions($published, $next))->pluck('field')->all())->toContain('decimal_places');
});

test('invalid input rules are rejected when saving a draft', function (array $overrides): void {
    expect(AccessFixtures::violatedRule(fn () => $this->kpi->draftDefinition('IR-INVALID', 'PROD-WOVEN', $overrides)))->toBe('invalid_definition');
})->with([
    'too many decimals' => [['decimal_places' => 5]],
    'decimals not a number' => [['decimal_places' => 'dua']],
    'minimum not a decimal' => [['min_valid_value' => '1,5']],
    'minimum above maximum' => [['min_valid_value' => '10', 'max_valid_value' => '5']],
]);

test('input rules of a published version are immutable, even through direct SQL', function (): void {
    $published = $this->kpi->publishedDefinition('IR-IMMUTABLE', 'PROD-WOVEN', ['decimal_places' => 2]);

    expect(fn () => DB::transaction(fn () => DB::table('kpi_definition_versions')->where('id', $published->id)->update(['decimal_places' => 4])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('kpi_definition_versions')->where('id', $published->id)->update(['max_valid_value' => '1'])))->toThrow(QueryException::class)
        ->and($published->fresh()?->decimal_places)->toBe(2);
});

test('HR edits the input rules in the definition editor', function (): void {
    $draft = $this->kpi->draftDefinition('IR-UI');

    Livewire::actingAs($this->kpi->author)->test('pages::kpi.definition-show', ['definition' => $draft->definition])
        ->set('form.decimal_places', '1')
        ->set('form.min_valid_value', '0')
        ->set('form.max_valid_value', '100')
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->assertSee('Jumlah desimal input');

    expect($draft->fresh()?->decimal_places)->toBe(1)
        ->and($draft->fresh()?->max_valid_value)->toBe('100')
        ->and(KpiDefinitionFactory::completeContent())->not->toHaveKey('decimal_places');
});
