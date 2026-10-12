<?php

declare(strict_types=1);

use App\Modules\KpiGovernance\Application\KpiDefinitionService;
use App\Modules\KpiGovernance\Application\VersionComparison;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\Shared\Domain\Exceptions\InvalidStateTransition;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->kpi = new KpiFixtures;
    $this->service = app(KpiDefinitionService::class);
});

test('HR drafts, submits for review, and a second HR account publishes', function (): void {
    $draft = $this->kpi->draftDefinition('PROD-OUTPUT');

    $submitted = $this->service->submitForReview($this->kpi->authorActor(), $draft->id, $draft->lock_version);
    $published = $this->service->publish($this->kpi->reviewerActor(), $submitted->id, $submitted->lock_version);

    expect($published->status)->toBe(LifecycleStatus::Published)
        ->and($published->published_by_user_id)->toBe($this->kpi->reviewer->id)
        ->and($published->submitted_by_user_id)->toBe($this->kpi->author->id)
        ->and(AuditLog::query()->where('entity_type', 'kpi_definition')->pluck('action')->all())
        ->toBe(['kpi_definition.created', 'kpi_definition.submitted', 'kpi_definition.published']);
});

test('the submitter cannot publish their own version', function (): void {
    $draft = $this->kpi->draftDefinition('PROD-SELF');
    $submitted = $this->service->submitForReview($this->kpi->authorActor(), $draft->id, $draft->lock_version);

    expect(AccessFixtures::violatedRule(fn () => $this->service->publish($this->kpi->authorActor(), $submitted->id, $submitted->lock_version)))
        ->toBe('self_review');
});

test('an incomplete definition cannot be submitted or published', function (): void {
    $draft = $this->kpi->draftDefinition('PROD-INCOMPLETE', 'PROD-WOVEN', ['target_rule' => '', 'signoff_reference' => '']);

    expect(AccessFixtures::violatedRule(fn () => $this->service->submitForReview($this->kpi->authorActor(), $draft->id, $draft->lock_version)))
        ->toBe('definition_incomplete');
});

test('a published version cannot be edited by the service or by direct SQL', function (): void {
    $published = $this->kpi->publishedDefinition('PROD-IMMUTABLE');

    expect(AccessFixtures::violatedRule(fn () => $this->service->updateDraft($this->kpi->authorActor(), $published->id, $published->lock_version, ['name' => 'Diubah'])))
        ->toBe('version_not_editable');

    expect(fn () => DB::table('kpi_definition_versions')->where('id', $published->id)->update(['name' => 'Diubah langsung']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('kpi_definition_versions')->where('id', $published->id)->update(['status' => 'draft']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('kpi_definition_versions')->where('id', $published->id)->delete())
        ->toThrow(QueryException::class);

    expect($published->fresh()?->name)->toBe('KPI PROD-IMMUTABLE')
        ->and($published->fresh()?->status)->toBe(LifecycleStatus::Published);
});

test('content under review is frozen as well', function (): void {
    $draft = $this->kpi->draftDefinition('PROD-REVIEW');
    $this->service->submitForReview($this->kpi->authorActor(), $draft->id, $draft->lock_version);

    expect(fn () => DB::table('kpi_definition_versions')->where('id', $draft->id)->update(['target_rule' => 'Diubah saat review']))
        ->toThrow(QueryException::class);
});

test('a change creates a new version and publishing it closes the previous one without rewriting it', function (): void {
    $v1 = AccessFixtures::on('2026-01-01', fn () => $this->kpi->submitAndPublish(
        $this->kpi->draftDefinition('PROD-YIELD', 'PROD-WOVEN', ['effective_from' => '2026-01-01']),
    ));

    $v2 = $this->service->startNewVersion($this->kpi->authorActor(), $v1->kpi_definition_id, 'Batas atas skor direvisi');

    expect($v2->version_number)->toBe(2)
        ->and($v2->status)->toBe(LifecycleStatus::Draft)
        ->and($v2->previous_version_id)->toBe($v1->id)
        ->and($v2->signoff_reference)->toBeNull();

    $parameters = [...$v1->score_parameters, 'score_cap' => '110'];
    $v2 = $this->service->updateDraft($this->kpi->authorActor(), $v2->id, $v2->lock_version, [
        ...$v1->content()->toArray(),
        'score_parameters' => $parameters,
        'signoff_reference' => 'TEST-SIGNOFF-002',
        'effective_from' => '2026-11-01',
    ]);

    $published = $this->kpi->submitAndPublish($v2);
    $v1 = $v1->fresh();

    expect($published->status)->toBe(LifecycleStatus::Published)
        ->and($v1?->status)->toBe(LifecycleStatus::Published)
        ->and($v1?->effective_until)->toBe('2026-11-01')
        ->and($v1?->score_parameters['score_cap'])->toBe('120')
        ->and(KpiDefinitionVersion::query()->where('kpi_definition_id', $v1?->kpi_definition_id)->effectiveOn('2026-10-31')->value('version_number'))->toBe(1)
        ->and(KpiDefinitionVersion::query()->where('kpi_definition_id', $v1?->kpi_definition_id)->effectiveOn('2026-11-01')->value('version_number'))->toBe(2);

    $changes = collect(app(VersionComparison::class)->definitions($v1, $published))->keyBy('field');

    expect($changes->keys()->all())->toContain('score_parameters', 'signoff_reference', 'effective_from')
        ->and($changes->get('signoff_reference'))->toMatchArray(['before' => 'TEST-SIGNOFF-001', 'after' => 'TEST-SIGNOFF-002'])
        ->and($published->change_reason)->toBe('Batas atas skor direvisi');
});

test('only one working version exists at a time', function (): void {
    $published = $this->kpi->publishedDefinition('PROD-ONE');
    $this->service->startNewVersion($this->kpi->authorActor(), $published->kpi_definition_id, 'Perubahan pertama');

    expect(AccessFixtures::violatedRule(fn () => $this->service->startNewVersion($this->kpi->authorActor(), $published->kpi_definition_id, 'Perubahan kedua')))
        ->toBe('working_version_exists');
});

test('effective dates may not overlap or supersede retroactively', function (): void {
    $v1 = AccessFixtures::on('2026-01-01', fn () => $this->kpi->submitAndPublish(
        $this->kpi->draftDefinition('PROD-DATES', 'PROD-WOVEN', ['effective_from' => '2026-01-01']),
    ));

    $v2 = $this->service->startNewVersion($this->kpi->authorActor(), $v1->kpi_definition_id, 'Mundur');
    $v2 = $this->service->updateDraft($this->kpi->authorActor(), $v2->id, $v2->lock_version, [...$v1->content()->toArray(), 'signoff_reference' => 'S2', 'effective_from' => '2026-09-01']);

    expect(AccessFixtures::violatedRule(fn () => $this->kpi->submitAndPublish($v2)))->toBe('retroactive_change');

    $v2 = $v2->fresh();
    $this->service->returnToDraft($this->kpi->reviewerActor(), $v2->id, $v2->lock_version, 'Tanggal salah');
    $v2 = $v2->fresh();
    $v2 = $this->service->updateDraft($this->kpi->authorActor(), $v2->id, $v2->lock_version, [...$v1->content()->toArray(), 'signoff_reference' => 'S2', 'effective_from' => '2025-12-01']);

    expect(AccessFixtures::violatedRule(fn () => $this->kpi->submitAndPublish($v2)))->toBe('effective_overlap');
});

test('retire ends a published version on a future date and is audited', function (): void {
    $published = $this->kpi->publishedDefinition('PROD-RETIRE');

    $retired = $this->service->retire($this->kpi->reviewerActor(), $published->id, $published->lock_version, '2026-12-01', 'KPI tidak lagi relevan');

    expect($retired->status)->toBe(LifecycleStatus::Retired)
        ->and($retired->effective_until)->toBe('2026-12-01')
        ->and(AuditLog::query()->where('action', 'kpi_definition.retired')->exists())->toBeTrue()
        ->and(AccessFixtures::violatedRule(fn () => $this->service->retire($this->kpi->reviewerActor(), $published->id, $retired->lock_version, '2026-10-01', 'Mundur')))
        ->toBe('retroactive_change');
});

test('lifecycle transitions follow DRAFT → REVIEW → PUBLISHED → RETIRED only', function (): void {
    $draft = $this->kpi->draftDefinition('PROD-FLOW');

    expect(fn () => $this->service->publish($this->kpi->reviewerActor(), $draft->id, $draft->lock_version))
        ->toThrow(InvalidStateTransition::class);

    $published = $this->kpi->submitAndPublish($draft);

    expect(fn () => $this->service->returnToDraft($this->kpi->reviewerActor(), $published->id, $published->lock_version, 'x'))
        ->toThrow(InvalidStateTransition::class);
});

test('score parameters are stored in canonical decimal form', function (): void {
    $draft = $this->kpi->draftDefinition('PROD-CANON', 'PROD-WOVEN', ['score_parameters' => [
        'missing_data_rule' => 'score_zero',
        'rounding_mode' => 'half_up',
        'rounding_scale' => '2',
        'score_floor' => '0.0000',
        'score_cap' => '120.50',
        'zero_target_rule' => 'not_applicable',
        'injected' => 'system("rm -rf /")',
    ]]);

    expect($draft->fresh()?->score_parameters)->toBe([
        'missing_data_rule' => 'score_zero',
        'rounding_mode' => 'half_up',
        'rounding_scale' => 2,
        'score_floor' => '0',
        'score_cap' => '120.5',
        'zero_target_rule' => 'not_applicable',
    ]);
});
