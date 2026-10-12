<?php

declare(strict_types=1);

use App\Modules\KpiGovernance\Application\CandidateImporter;
use App\Modules\KpiGovernance\Application\KpiDefinitionService;
use App\Modules\KpiGovernance\Application\KpiTemplateService;
use App\Modules\KpiGovernance\Application\TemplateResolver;
use App\Modules\KpiGovernance\Application\VersionComparison;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateItem;
use App\Modules\KpiGovernance\Infrastructure\PrdCandidateCatalog;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Domain\Exceptions\ConcurrencyConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->kpi = new KpiFixtures;
    $this->templates = app(KpiTemplateService::class);
});

test('a template publishes only with total weight exactly 100 percent', function (): void {
    $output = $this->kpi->publishedDefinition('W-OUTPUT');
    $reject = $this->kpi->publishedDefinition('W-REJECT');

    $draft = $this->kpi->draftTemplate('T-WOVEN-STAFF', [$output->id => '60', $reject->id => '30']);

    expect(AccessFixtures::violatedRule(fn () => $this->kpi->publishTemplate($draft)))->toBe('template_incomplete')
        ->and($this->templates->publishProblems($draft))->toContain('Total bobot harus tepat 100%. Total saat ini 90%.');

    $item = KpiTemplateItem::query()->where('kpi_template_version_id', $draft->id)->where('kpi_definition_version_id', $reject->id)->firstOrFail();
    $this->templates->updateItem($this->kpi->authorActor(), $item->id, $draft->fresh()->lock_version, '40', 'KPI individu', true, null);

    $published = $this->kpi->publishTemplate($draft->fresh());

    expect($published->status)->toBe(LifecycleStatus::Published)
        ->and($published->published_by_user_id)->toBe($this->kpi->reviewer->id);
});

test('a template can only publish KPI versions that are published and in effect', function (): void {
    $draftKpi = $this->kpi->draftDefinition('W-DRAFTKPI');
    $template = $this->kpi->draftTemplate('T-DRAFTKPI', [$draftKpi->id => '100']);

    expect($this->templates->publishProblems($template))->toContain("KPI {$draftKpi->name} (v1) belum published.");
});

test('items of a published template are immutable, even through direct SQL', function (): void {
    $kpi = $this->kpi->publishedDefinition('W-IMM');
    $published = $this->kpi->publishTemplate($this->kpi->draftTemplate('T-IMM', [$kpi->id => '100']));
    $item = KpiTemplateItem::query()->where('kpi_template_version_id', $published->id)->firstOrFail();

    expect(AccessFixtures::violatedRule(fn () => $this->templates->updateItem($this->kpi->authorActor(), $item->id, $published->lock_version, '50', null, true, null)))
        ->toBe('version_not_editable');

    expect(fn () => DB::transaction(fn () => DB::table('kpi_template_items')->where('id', $item->id)->update(['weight' => '50.0000'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('kpi_template_items')->where('id', $item->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('kpi_template_items')->insert([
            'public_id' => '01KTESTITEM000000000000000',
            'kpi_template_version_id' => $published->id,
            'kpi_definition_version_id' => $kpi->id,
            'weight' => '10.0000',
            'sort_order' => 9,
            'is_mandatory' => true,
        ])))->toThrow(QueryException::class);

    expect($item->fresh()?->weight)->toBe('100.0000');
});

test('two periods on different dates resolve different template versions without changing either', function (): void {
    $output = AccessFixtures::on('2026-01-01', fn () => $this->kpi->submitAndPublish($this->kpi->draftDefinition('W-OUT', 'PROD-WOVEN', ['effective_from' => '2026-01-01'])));
    $waste = AccessFixtures::on('2026-01-01', fn () => $this->kpi->submitAndPublish($this->kpi->draftDefinition('W-WASTE', 'PROD-WOVEN', ['effective_from' => '2026-01-01'])));

    $v1 = AccessFixtures::on('2026-01-01', fn () => $this->kpi->publishTemplate($this->kpi->draftTemplate('T-PERIODS', [$output->id => '70', $waste->id => '30'], 'PROD-WOVEN', '2026-01-01')));

    $v2 = $this->templates->startNewVersion($this->kpi->authorActor(), $v1->kpi_template_id, 'Bobot waste dinaikkan');
    $items = KpiTemplateItem::query()->where('kpi_template_version_id', $v2->id)->orderBy('sort_order')->get();
    $this->templates->updateItem($this->kpi->authorActor(), $items[0]->id, $v2->fresh()->lock_version, '60', null, true, null);
    $this->templates->updateItem($this->kpi->authorActor(), $items[1]->id, $v2->fresh()->lock_version, '40', null, true, null);
    $this->templates->updateDraftHeader($this->kpi->authorActor(), $v2->id, $v2->fresh()->lock_version, null, 'TEST-SIGNOFF-T2', '2026-11-01');
    $v2 = $this->kpi->publishTemplate($v2->fresh());

    $resolver = app(TemplateResolver::class);
    $october = $resolver->forScope(AccessFixtures::unit('PROD-WOVEN')->id, 'employee', null, '2026-10-15');
    $november = $resolver->forScope(AccessFixtures::unit('PROD-WOVEN')->id, 'employee', null, '2026-11-15');

    expect($october?->version_number)->toBe(1)
        ->and($november?->version_number)->toBe(2)
        ->and($october?->items()->pluck('weight')->all())->toBe(['70.0000', '30.0000'])
        ->and($november?->items()->pluck('weight')->all())->toBe(['60.0000', '40.0000'])
        ->and($v1->fresh()?->effective_until)->toBe('2026-11-01');

    $comparison = app(VersionComparison::class)->templates($v1->fresh(), $v2);

    expect(collect($comparison['items'])->pluck('change')->unique()->all())->toBe(['diubah'])
        ->and(collect($comparison['header'])->pluck('field')->all())->toContain('signoff_reference', 'effective_from')
        ->and($v2->change_reason)->toBe('Bobot waste dinaikkan');
});

test('one scope has one template and duplicate KPIs are rejected', function (): void {
    $kpi = $this->kpi->publishedDefinition('W-DUP');
    $version = $this->kpi->draftTemplate('T-SCOPE', [$kpi->id => '50']);

    expect(AccessFixtures::violatedRule(fn () => $this->templates->createTemplate($this->kpi->authorActor(), 'T-SCOPE-2', 'Duplikat', AccessFixtures::unit('PROD-WOVEN')->id, 'employee', null, null)))
        ->toBe('template_exists')
        ->and(AccessFixtures::violatedRule(fn () => $this->templates->addItem($this->kpi->authorActor(), $version->id, $version->lock_version, $kpi->id, '50', null, true, null)))
        ->toBe('duplicate_kpi');

    $this->templates->createTemplate($this->kpi->authorActor(), 'T-SCOPE-OPERATOR', 'Operator', AccessFixtures::unit('PROD-WOVEN')->id, 'employee', 'Operator', null);
});

test('a stale builder cannot change a template version changed by someone else', function (): void {
    $kpi = $this->kpi->publishedDefinition('W-LOCK');
    $version = $this->kpi->draftTemplate('T-LOCK', []);
    $stale = $version->lock_version;

    $this->templates->addItem($this->kpi->authorActor(), $version->id, $stale, $kpi->id, '100', null, true, null);

    expect(fn () => $this->templates->updateDraftHeader($this->kpi->authorActor(), $version->id, $stale, 'catatan', null, null))
        ->toThrow(ConcurrencyConflict::class);
});

test('PRD candidates are imported as clearly labelled drafts that cannot be published', function (): void {
    $result = app(CandidateImporter::class)->import(Actor::system('test'));
    $again = app(CandidateImporter::class)->import(Actor::system('test'));

    expect($result['created'])->toBe(PrdCandidateCatalog::count())
        ->and($again['created'])->toBe(0)
        ->and(KpiDefinition::query()->where('origin', KpiDefinition::ORIGIN_PRD_CANDIDATE)->count())->toBe(PrdCandidateCatalog::count())
        ->and(KpiDefinitionVersion::query()->where('status', '<>', LifecycleStatus::Draft->value)->count())->toBe(0)
        ->and(KpiDefinition::query()->where('code', 'CAND-COMSUP-DESIGN-A-01')->value('owner_unit_id'))
        ->not->toBe(KpiDefinition::query()->where('code', 'CAND-COMSUP-DESIGN-B-01')->value('owner_unit_id'));

    $candidate = KpiDefinitionVersion::query()->whereHas('definition', fn ($query) => $query->where('code', 'CAND-PROD-WOVEN-01'))->firstOrFail();

    expect($candidate->name)->toBe('Good output')
        ->and(AccessFixtures::violatedRule(fn () => app(KpiDefinitionService::class)->submitForReview($this->kpi->authorActor(), $candidate->id, $candidate->lock_version)))
        ->toBe('definition_incomplete');
});
