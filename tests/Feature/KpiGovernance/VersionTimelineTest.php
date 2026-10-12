<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\KpiGovernance\Application\KpiDefinitionService;
use App\Modules\KpiGovernance\Application\KpiTemplateService;
use App\Modules\KpiGovernance\Application\TemplateResolver;
use App\Modules\KpiGovernance\Domain\LifecycleStatus;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinitionVersion;
use App\Modules\Shared\Domain\Exceptions\ConcurrencyConflict;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;

/*
 * Regression tests for the Step 06 review findings: supersede/retire race,
 * withdrawing a scheduled version, insert guard, template-level timeline
 * rules, pinned KPI warnings, and adding a KPI from outside the scope.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->kpi = new KpiFixtures;
    $this->definitions = app(KpiDefinitionService::class);
    $this->templates = app(KpiTemplateService::class);
});

function publishedSince2026(object $test, string $code): KpiDefinitionVersion
{
    return AccessFixtures::on('2026-01-01', fn () => $test->kpi->submitAndPublish(
        $test->kpi->draftDefinition($code, 'PROD-WOVEN', ['effective_from' => '2026-01-01']),
    ));
}

function scheduleNextVersion(object $test, KpiDefinitionVersion $current, string $from, string $signoff): KpiDefinitionVersion
{
    $next = $test->definitions->startNewVersion($test->kpi->authorActor(), $current->kpi_definition_id, "Versi mulai {$from}");

    return $test->definitions->updateDraft($test->kpi->authorActor(), $next->id, $next->lock_version, [
        ...$current->content()->toArray(),
        'signoff_reference' => $signoff,
        'effective_from' => $from,
    ]);
}

test('superseding bumps the old version lock so a stale retire cannot reopen an overlap', function (): void {
    $v1 = publishedSince2026($this, 'TL-RACE');
    $staleLock = $v1->lock_version;

    $this->kpi->submitAndPublish(scheduleNextVersion($this, $v1, '2026-11-01', 'S2'));

    // Extending past the new version's start is refused outright ...
    expect(AccessFixtures::violatedRule(fn () => $this->definitions->retire($this->kpi->reviewerActor(), $v1->id, $staleLock, '2026-12-01', 'Retire basi')))
        ->toBe('invalid_period');

    // ... and a stale form (lock read before the supersede) conflicts.
    expect(fn () => $this->definitions->retire($this->kpi->reviewerActor(), $v1->id, $staleLock, '2026-10-20', 'Retire basi'))
        ->toThrow(ConcurrencyConflict::class)
        ->and($v1->fresh()?->effective_until)->toBe('2026-11-01');
});

test('a scheduled version can be withdrawn before it starts and the previous version applies again', function (): void {
    $v1 = publishedSince2026($this, 'TL-WITHDRAW');
    $v2 = $this->kpi->submitAndPublish(scheduleNextVersion($this, $v1, '2026-11-01', 'S2'));
    $resolve = fn (string $date) => KpiDefinitionVersion::query()->where('kpi_definition_id', $v1->kpi_definition_id)->effectiveOn($date)->value('version_number');

    expect($v1->fresh()?->effective_until)->toBe('2026-11-01')
        ->and($resolve('2026-11-15'))->toBe(2);

    $withdrawn = $this->definitions->withdrawScheduled($this->kpi->reviewerActor(), $v2->id, $v2->lock_version, 'Versi dipublish keliru');

    expect($withdrawn->status)->toBe(LifecycleStatus::Retired)
        ->and($withdrawn->effective_until)->toBe('2026-11-01')
        ->and($v1->fresh()?->effective_until)->toBeNull()
        ->and($resolve('2026-11-15'))->toBe(1)
        ->and(AuditLog::query()->where('action', 'kpi_definition.withdrawn')->exists())->toBeTrue()
        ->and(AccessFixtures::violatedRule(fn () => $this->definitions->withdrawScheduled($this->kpi->reviewerActor(), $v1->id, $v1->fresh()->lock_version, 'Sudah berlaku')))
        ->toBe('not_scheduled');

    // The withdrawn version has an empty interval and does not block a corrected one.
    $v3 = $this->kpi->submitAndPublish(scheduleNextVersion($this, $v1->fresh(), '2026-11-01', 'S3'));

    expect($v3->status)->toBe(LifecycleStatus::Published)
        ->and($resolve('2026-11-15'))->toBe(3);
});

test('new versions can only be inserted as drafts, even with direct SQL', function (): void {
    $draft = $this->kpi->draftDefinition('TL-INSERT');

    expect(fn () => DB::transaction(fn () => DB::table('kpi_definition_versions')->insert([
        'public_id' => '01KTESTVERSION000000000000',
        'kpi_definition_id' => $draft->kpi_definition_id,
        'version_number' => 2,
        'status' => 'published',
        'name' => 'Diselundupkan',
        'lock_version' => 1,
    ])))->toThrow(QueryException::class);

    expect(KpiDefinitionVersion::query()->where('kpi_definition_id', $draft->kpi_definition_id)->count())->toBe(1);
});

test('template versions follow the same retroactive, return, overlap, withdraw, and retire rules', function (): void {
    $kpi = publishedSince2026($this, 'TL-TEMPLATE');
    $v1 = AccessFixtures::on('2026-01-01', fn () => $this->kpi->publishTemplate($this->kpi->draftTemplate('TL-T-RULES', [$kpi->id => '100'], 'PROD-WOVEN', '2026-01-01')));
    $resolver = app(TemplateResolver::class);
    $woven = AccessFixtures::unit('PROD-WOVEN')->id;

    $v2 = $this->templates->startNewVersion($this->kpi->authorActor(), $v1->kpi_template_id, 'Revisi');
    $v2 = $this->templates->updateDraftHeader($this->kpi->authorActor(), $v2->id, $v2->lock_version, null, 'S2', '2026-09-01');
    $submitted = $this->templates->submitForReview($this->kpi->authorActor(), $v2->id, $v2->lock_version);

    expect(AccessFixtures::violatedRule(fn () => $this->templates->publish($this->kpi->reviewerActor(), $submitted->id, $submitted->lock_version)))
        ->toBe('retroactive_change');

    $returned = $this->templates->returnToDraft($this->kpi->reviewerActor(), $submitted->id, $submitted->lock_version, 'Tanggal mundur');

    expect($returned->status)->toBe(LifecycleStatus::Draft)
        ->and($returned->return_reason)->toBe('Tanggal mundur');

    $returned = $this->templates->updateDraftHeader($this->kpi->authorActor(), $returned->id, $returned->lock_version, null, 'S2', '2026-01-01');
    $submitted = $this->templates->submitForReview($this->kpi->authorActor(), $returned->id, $returned->lock_version);

    expect(AccessFixtures::violatedRule(fn () => $this->templates->publish($this->kpi->reviewerActor(), $submitted->id, $submitted->lock_version)))
        ->toBe('effective_overlap');

    $returned = $this->templates->returnToDraft($this->kpi->reviewerActor(), $submitted->id, $submitted->lock_version, 'Sebelum v1');
    $returned = $this->templates->updateDraftHeader($this->kpi->authorActor(), $returned->id, $returned->lock_version, null, 'S2', '2026-11-01');
    $v2 = $this->kpi->publishTemplate($returned);

    expect($resolver->forScope($woven, 'employee', null, '2026-11-15')?->version_number)->toBe(2);

    $withdrawn = $this->templates->withdrawScheduled($this->kpi->reviewerActor(), $v2->id, $v2->lock_version, 'Keliru');

    expect($withdrawn->status)->toBe(LifecycleStatus::Retired)
        ->and($v1->fresh()?->effective_until)->toBeNull()
        ->and($resolver->forScope($woven, 'employee', null, '2026-11-15')?->version_number)->toBe(1);

    $retired = $this->templates->retire($this->kpi->reviewerActor(), $v1->id, $v1->fresh()->lock_version, '2026-12-01', 'Template diganti');

    expect($retired->status)->toBe(LifecycleStatus::Retired)
        ->and($resolver->forScope($woven, 'employee', null, '2026-11-30')?->version_number)->toBe(1)
        ->and($resolver->forScope($woven, 'employee', null, '2026-12-01'))->toBeNull();
});

test('a template warns when a pinned KPI version ends while the template is in force', function (): void {
    $kpi = publishedSince2026($this, 'TL-ENDS');
    $template = AccessFixtures::on('2026-01-01', fn () => $this->kpi->publishTemplate($this->kpi->draftTemplate('TL-T-ENDS', [$kpi->id => '100'], 'PROD-WOVEN', '2026-01-01')));

    expect($this->templates->pinnedKpiWarnings($template))->toBe([]);

    $this->definitions->retire($this->kpi->reviewerActor(), $kpi->id, $kpi->fresh()->lock_version, '2026-12-01', 'KPI diganti');

    expect($this->templates->pinnedKpiWarnings($template->fresh()))->toHaveCount(1);
});

test('a template builder cannot add a KPI from outside the builder scope', function (): void {
    $hrAccounting = AccessFixtures::userWithRole(Role::HrPerformance, 'ACC');
    $wovenKpi = $this->kpi->publishedDefinition('TL-WOVEN-KPI', 'PROD-WOVEN');
    $arKpi = $this->kpi->publishedDefinition('TL-AR-KPI', 'ACC-AR');
    $arTemplate = $this->kpi->draftTemplate('TL-T-AR', [], 'ACC-AR');

    Livewire::actingAs($hrAccounting)->test('pages::kpi.template-show', ['template' => $arTemplate->template])
        ->set('itemDefinition', $wovenKpi->public_id)
        ->set('itemWeight', '100')
        ->call('addItem')
        ->assertForbidden();

    Livewire::actingAs($hrAccounting)->test('pages::kpi.template-show', ['template' => $arTemplate->template])
        ->set('itemDefinition', $arKpi->public_id)
        ->set('itemWeight', '100')
        ->call('addItem')
        ->assertHasNoErrors();

    expect($arTemplate->items()->count())->toBe(1);
});
