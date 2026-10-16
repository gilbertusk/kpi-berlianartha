<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Quality\Application\NcrQuery;
use App\Modules\Quality\Application\NcrService;
use App\Modules\Quality\Application\QualityAccess;
use App\Modules\Quality\Domain\NcrStage;
use App\Modules\Quality\Infrastructure\Persistence\Models\QualityNcr;
use App\Modules\Quality\Infrastructure\Persistence\Models\QualityNcrStage;
use App\Modules\Shared\Domain\Exceptions\ConcurrencyConflict;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;
use Tests\Support\ProductionHarness as Harness;
use Tests\Support\ReportHarness;

/*
 * NCR and CAPA: traceability to the inspection, the PRD §10.3 lifecycle,
 * who may move an NCR, evidence, reopen, corrections, overdue, duplicates,
 * repeat links, and the linked KPI version.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->qc = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-QC');
    $this->woven = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    $this->operator = AccessFixtures::user('Operator Woven');
    AccessFixtures::employee($this->operator, 'PROD-WOVEN');
    AccessFixtures::grant($this->operator, Role::Employee);
    $this->flexo = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-FLEXO');
    $this->management = AccessFixtures::userWithRole(Role::Management, 'BAL');
    $this->auditor = AccessFixtures::userWithRole(Role::Auditor, 'BAL');
    $this->hr = AccessFixtures::userWithRole(Role::HrPerformance, 'BAL');
    $this->sales = AccessFixtures::userWithRole(Role::TeamLeader, 'COMSUP-SALES');
    $this->kpi = (new KpiFixtures)->publishedDefinition('QC-REJECT', 'PROD-QC');
    $this->travelTo(CarbonImmutable::parse(Harness::NOW, 'UTC'));
    $this->ncrs = app(NcrService::class);

    // inspection_id,inspection_stage,inspected_at,work_order,product_code,lot,supplier_code,unit,inspected_qty,accepted_qty,rejected_qty,defect_code
    $this->inspections = Harness::load($this->qc, BatchKind::Inspection, [
        'INS-1,in_process,2026-10-14 08:00,WO-100,P-1,L-7,,pcs,500,480,20,DF-WEAVE',
        'INS-2,incoming,2026-10-14 08:30,,YARN-1,L-9,SUP-1,kg,100,95,5,',
    ], 'PROD-QC', '2026-10-14');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function raiseNcr(object $test, array $overrides = [], ?User $by = null): QualityNcr
{
    $by ??= $test->qc;

    return $test->ncrs->raise(Harness::actor($by), $by, [
        'responsible_unit' => 'PROD-WOVEN',
        'source_type' => 'inspection',
        'source_reference' => 'INS-1',
        'description' => 'Benang putus pada pola tenun',
        'owner_user_id' => $test->qc->id,
        'pic_user_id' => $test->qc->id,
        'due_date' => '2026-10-16',
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $input
 */
function advanceNcr(object $test, QualityNcr $ncr, User $by, array $input = []): QualityNcr
{
    $ncr->refresh();

    return $test->ncrs->advance(Harness::actor($by), $by, $ncr->id, $ncr->lock_version, [
        'content' => 'Tercatat',
        'next_pic_user_id' => $test->woven->id,
        'next_due_date' => '2026-10-20',
        ...$input,
    ]);
}

function walkToVerification(object $test, QualityNcr $ncr): QualityNcr
{
    advanceNcr($test, $ncr, $test->qc);                                                      // TEMUAN → CONTAINMENT
    advanceNcr($test, $ncr, $test->woven, ['content' => 'Lot L-7 ditahan']);                  // → ROOT_CAUSE
    advanceNcr($test, $ncr, $test->woven, ['content' => 'Tegangan benang tidak dikalibrasi']); // → CORRECTIVE_ACTION

    return advanceNcr($test, $ncr, $test->woven, ['content' => 'Kalibrasi ulang dan sortir lot', 'next_pic_user_id' => $test->qc->id]); // → VERIFICATION
}

test('an NCR from an inspection traces to its source and walks the PRD lifecycle to closure', function (): void {
    $ncr = raiseNcr($this);

    expect($ncr->number)->toBe('NCR-2026-0001')
        ->and($ncr->stage)->toBe(NcrStage::Finding)
        ->and($ncr->sourceRecord?->event_key)->toBe('INS-1')
        ->and($ncr->sourceRecord?->production_batch_id)->toBe($this->inspections->id)
        ->and([$ncr->category_code, $ncr->work_order, $ncr->product_code, $ncr->lot, $ncr->quantity, $ncr->unit])->toBe(['DF-WEAVE', 'WO-100', 'P-1', 'L-7', '20.000', 'pcs'])
        ->and($ncr->detected_at->toIso8601String())->toBe('2026-10-14T01:00:00+00:00')
        ->and($ncr->severity)->toBeNull();

    walkToVerification($this, $ncr);
    $closed = advanceNcr($this, $ncr, $this->qc, ['content' => 'Sampel 3 lot berikut sesuai', 'evidence_reference' => 'QC-REC-2026-118']);
    $timeline = app(NcrQuery::class)->timeline($closed);

    expect($closed->stage)->toBe(NcrStage::Closed)
        ->and($closed->closed_at)->not->toBeNull()
        ->and(array_map(fn (array $row): string => $row['stage']->stage->value, $timeline))->toBe(['TEMUAN', 'CONTAINMENT', 'ROOT_CAUSE', 'CORRECTIVE_ACTION', 'VERIFICATION'])
        ->and(collect($timeline)->every(fn (array $row): bool => $row['stage']->outcome === QualityNcrStage::COMPLETED))->toBeTrue()
        ->and($closed->events()->pluck('action')->all())->toBe(['raised', 'advanced', 'advanced', 'advanced', 'advanced', 'closed'])
        ->and(AuditLog::query()->where('entity_type', 'quality_ncr')->where('entity_id', 'NCR-2026-0001')->count())->toBe(6)
        ->and(AuditLog::query()->where('entity_type', 'quality_ncr')->get()->pluck('after_values')->toJson())->not->toContain('QC-REC-2026-118');
});

test('only lawful transitions by allowed people move an NCR', function (): void {
    expect(fn () => raiseNcr($this, by: $this->woven))->toThrow(AuthorizationException::class);

    $ncr = raiseNcr($this);

    // Temuan is confirmed by Quality Control, not by the responsible team.
    expect(fn () => advanceNcr($this, $ncr, $this->woven))->toThrow(AuthorizationException::class);
    advanceNcr($this, $ncr, $this->qc, ['next_pic_user_id' => $this->operator->id]);

    // Containment: the stage PIC or the responsible team; not another team, not readers.
    expect(fn () => advanceNcr($this, $ncr, $this->flexo, ['content' => 'x']))->toThrow(AuthorizationException::class)
        ->and(fn () => advanceNcr($this, $ncr, $this->management, ['content' => 'x']))->toThrow(AuthorizationException::class)
        ->and(fn () => advanceNcr($this, $ncr, $this->sales, ['content' => 'x']))->toThrow(AuthorizationException::class);
    advanceNcr($this, $ncr, $this->operator, ['content' => 'Lot ditahan oleh PIC', 'next_pic_user_id' => $this->flexo->id]);

    // The operator is no PIC of root cause; a PIC from another team is.
    expect(fn () => advanceNcr($this, $ncr, $this->operator, ['content' => 'x']))->toThrow(AuthorizationException::class);
    advanceNcr($this, $ncr, $this->flexo, ['content' => 'Akar masalah oleh PIC lintas team']);
    advanceNcr($this, $ncr, $this->woven, ['content' => 'Tindakan', 'next_pic_user_id' => $this->qc->id]);

    // Verification and closure belong to Quality Control.
    expect(fn () => advanceNcr($this, $ncr, $this->woven, ['content' => 'x', 'evidence_reference' => 'E-1']))->toThrow(AuthorizationException::class)
        ->and(AccessFixtures::violatedRule(fn () => $this->ncrs->reopen(Harness::actor($this->qc), $this->qc, $ncr->id, $ncr->refresh()->lock_version, NcrStage::RootCause, 'x', $this->qc->id, '2026-10-20')))->toBe('invalid_transition');

    advanceNcr($this, $ncr, $this->qc, ['content' => 'Terverifikasi', 'evidence_reference' => 'E-1']);

    expect(AccessFixtures::violatedRule(fn () => advanceNcr($this, $ncr, $this->qc)))->toBe('invalid_transition')
        ->and(AccessFixtures::violatedRule(fn () => $this->ncrs->returnToCorrectiveAction(Harness::actor($this->qc), $this->qc, $ncr->id, $ncr->refresh()->lock_version, 'x', ['next_pic_user_id' => $this->qc->id, 'next_due_date' => '2026-10-20'])))->toBe('invalid_transition')
        ->and(AccessFixtures::violatedRule(fn () => $this->ncrs->reopen(Harness::actor($this->qc), $this->qc, $ncr->id, $ncr->refresh()->lock_version, NcrStage::Finding, 'x', $this->qc->id, '2026-10-20')))->toBe('invalid_transition')
        ->and(fn () => $this->ncrs->reopen(Harness::actor($this->woven), $this->woven, $ncr->id, $ncr->refresh()->lock_version, NcrStage::RootCause, 'x', $this->qc->id, '2026-10-20'))->toThrow(AuthorizationException::class);

    // History never changes and nothing is deleted.
    expect(fn () => DB::transaction(fn () => DB::table('quality_ncr_stages')->where('quality_ncr_id', $ncr->id)->update(['content' => 'diubah'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('quality_ncr_stages')->where('quality_ncr_id', $ncr->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('quality_ncr_events')->where('quality_ncr_id', $ncr->id)->update(['reason' => 'x'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('quality_ncrs')->where('id', $ncr->id)->delete()))->toThrow(QueryException::class);
});

test('an NCR never closes without verification evidence; optional CAPA parts need approval', function (): void {
    $ncr = raiseNcr($this);
    advanceNcr($this, $ncr, $this->qc);

    expect(AccessFixtures::violatedRule(fn () => advanceNcr($this, $ncr, $this->woven, ['content' => ''])))->toBe('content_required');
    advanceNcr($this, $ncr, $this->woven, ['content' => 'Containment']);

    expect(AccessFixtures::violatedRule(fn () => advanceNcr($this, $ncr, $this->woven, ['content' => 'Akar', 'root_cause_method' => '5WHY'])))->toBe('invalid_root_cause_method');
    config(['quality.root_cause_methods' => ['5WHY', 'FISHBONE']]);
    advanceNcr($this, $ncr, $this->woven, ['content' => 'Akar', 'root_cause_method' => '5WHY']);

    expect(AccessFixtures::violatedRule(fn () => advanceNcr($this, $ncr, $this->woven, ['content' => 'Tindakan', 'preventive_action' => 'Jadwal kalibrasi'])))->toBe('preventive_action_disabled');
    config(['quality.preventive_action_enabled' => true]);
    advanceNcr($this, $ncr, $this->woven, ['content' => 'Tindakan', 'preventive_action' => 'Jadwal kalibrasi', 'next_pic_user_id' => $this->qc->id]);

    expect(AccessFixtures::violatedRule(fn () => advanceNcr($this, $ncr, $this->qc, ['content' => 'Selesai'])))->toBe('evidence_required')
        ->and(AccessFixtures::violatedRule(fn () => advanceNcr($this, $ncr, $this->qc, ['content' => 'Selesai', 'evidence_reference' => 'E-9', 'effectiveness_result' => 'effective'])))->toBe('effectiveness_not_required');

    config(['quality.effectiveness_review_required' => true]);

    expect(AccessFixtures::violatedRule(fn () => advanceNcr($this, $ncr, $this->qc, ['content' => 'Selesai', 'evidence_reference' => 'E-9'])))->toBe('effectiveness_required')
        ->and(AccessFixtures::violatedRule(fn () => advanceNcr($this, $ncr, $this->qc, ['content' => 'Selesai', 'evidence_reference' => 'E-9', 'effectiveness_result' => 'not_effective'])))->toBe('effectiveness_required')
        ->and(advanceNcr($this, $ncr, $this->qc, ['content' => 'Selesai', 'evidence_reference' => 'E-9', 'effectiveness_result' => 'effective'])->stage)->toBe(NcrStage::Closed);

    $stages = QualityNcrStage::query()->where('quality_ncr_id', $ncr->id)->orderBy('sequence')->get();

    expect($stages[2]->root_cause_method)->toBe('5WHY')
        ->and($stages[3]->preventive_action)->toBe('Jadwal kalibrasi')
        ->and($stages[4]->effectiveness_result)->toBe('effective');

    // A setting may ask for evidence at more stages.
    config(['quality.evidence_required_stages' => ['CONTAINMENT']]);
    $other = raiseNcr($this, ['source_reference' => 'INS-2', 'category_code' => 'DF-YARN']);
    advanceNcr($this, $other, $this->qc);

    expect(AccessFixtures::violatedRule(fn () => advanceNcr($this, $other, $this->woven, ['content' => 'Containment'])))->toBe('evidence_required');
});

test('a failed verification returns to corrective action and a reopen keeps the closed cycle', function (): void {
    $ncr = walkToVerification($this, raiseNcr($this));
    $returned = $this->ncrs->returnToCorrectiveAction(Harness::actor($this->qc), $this->qc, $ncr->id, $ncr->lock_version, 'Sampel masih cacat', ['next_pic_user_id' => $this->woven->id, 'next_due_date' => '2026-10-22']);

    expect($returned->stage)->toBe(NcrStage::CorrectiveAction);

    advanceNcr($this, $ncr, $this->woven, ['content' => 'Ganti tensioner', 'next_pic_user_id' => $this->qc->id]);
    advanceNcr($this, $ncr, $this->qc, ['content' => 'Sesuai', 'evidence_reference' => 'QC-REC-2026-130']);
    $ncr->refresh();
    $reopened = $this->ncrs->reopen(Harness::actor($this->qc), $this->qc, $ncr->id, $ncr->lock_version, NcrStage::RootCause, 'Complaint customer untuk lot yang sama', $this->woven->id, '2026-10-25');
    $timeline = app(NcrQuery::class)->timeline($reopened);

    expect($reopened->stage)->toBe(NcrStage::RootCause)
        ->and($reopened->cycle)->toBe(2)
        ->and($reopened->closed_at)->toBeNull()
        ->and(array_map(fn (array $row): string => $row['stage']->stage->value.'#'.$row['stage']->cycle.':'.($row['stage']->outcome ?? 'open'), $timeline))->toBe([
            'TEMUAN#1:completed', 'CONTAINMENT#1:completed', 'ROOT_CAUSE#1:completed', 'CORRECTIVE_ACTION#1:completed',
            'VERIFICATION#1:returned', 'CORRECTIVE_ACTION#1:completed', 'VERIFICATION#1:completed', 'ROOT_CAUSE#2:open',
        ])
        ->and($reopened->events()->pluck('action')->all())->toBe(['raised', 'advanced', 'advanced', 'advanced', 'advanced', 'returned', 'advanced', 'closed', 'reopened']);
});

test('overdue counts from the open stage due date and reconciles with the stage timeline', function (): void {
    $ncr = raiseNcr($this);   // TEMUAN due 2026-10-16
    $query = app(NcrQuery::class);

    expect($query->rows($this->qc)[0]['overdue_days'])->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-19 03:00:00', 'UTC'));

    expect($query->rows($this->qc)[0]['overdue_days'])->toBe(3)
        ->and($query->summary($this->qc))->toMatchArray(['overdue' => 1, 'verification' => 0])
        ->and($query->rows($this->qc, ['flag' => NcrQuery::OVERDUE]))->toHaveCount(1);

    advanceNcr($this, $ncr, $this->qc, ['next_due_date' => '2026-10-25']);
    $timeline = $query->timeline($ncr);

    expect($timeline[0])->toMatchArray(['completed_on' => '2026-10-19', 'late_days' => 3])
        ->and($timeline[1]['late_days'])->toBe(0)
        ->and($query->summary($this->qc)['overdue'])->toBe(0)
        ->and(AccessFixtures::violatedRule(fn () => advanceNcr($this, $ncr, $this->woven, ['content' => 'x', 'next_due_date' => '2026-10-18'])))->toBe('invalid_due_date');
});

test('the same defect is not raised twice and repeat defects are linked by hand only', function (): void {
    $first = raiseNcr($this);

    expect(AccessFixtures::violatedRule(fn () => raiseNcr($this)))->toBe('duplicate_ncr')
        ->and(AccessFixtures::violatedRule(fn () => raiseNcr($this, ['source_reference' => 'INS-404'])))->toBe('unknown_inspection');

    $repeat = raiseNcr($this, ['source_type' => 'complaint', 'source_reference' => 'CMP-2026-09', 'category_code' => 'DF-WEAVE', 'repeat_of' => $first->number, 'detected_at' => '2026-10-14 09:00']);
    $unlinked = raiseNcr($this, ['source_type' => 'return', 'source_reference' => 'RT-2026-02', 'category_code' => 'DF-WEAVE', 'detected_at' => '2026-10-14 09:10']);
    $rows = collect(app(NcrQuery::class)->rows($this->qc, ['flag' => NcrQuery::REPEAT]))->keyBy(fn (array $row): string => $row['ncr']->number);

    expect($repeat->repeatOf?->number)->toBe('NCR-2026-0001')
        ->and($repeat->production_record_id)->toBeNull()
        ->and($unlinked->repeat_of_ncr_id)->toBeNull()   // no matching rule is approved
        ->and($rows->keys()->sort()->values()->all())->toBe(['NCR-2026-0001', 'NCR-2026-0002'])
        ->and($rows['NCR-2026-0001']['repeats'])->toBe(1)
        ->and(AccessFixtures::violatedRule(fn () => raiseNcr($this, ['source_type' => 'complaint', 'source_reference' => 'CMP-1', 'category_code' => 'DF-WEAVE', 'repeat_of' => 'NCR-2026-0999', 'detected_at' => '2026-10-14 09:00'])))->toBe('invalid_repeat');
});

test('a concurrent review with a stale version is refused', function (): void {
    $ncr = raiseNcr($this);
    $stale = $ncr->lock_version;
    $this->ncrs->advance(Harness::actor($this->qc), $this->qc, $ncr->id, $stale, ['next_pic_user_id' => $this->woven->id, 'next_due_date' => '2026-10-20']);

    expect(fn () => $this->ncrs->advance(Harness::actor($this->woven), $this->woven, $ncr->id, $stale, ['content' => 'x', 'next_pic_user_id' => $this->woven->id, 'next_due_date' => '2026-10-20']))->toThrow(ConcurrencyConflict::class)
        ->and(fn () => $this->ncrs->correct(Harness::actor($this->qc), $this->qc, $ncr->id, $stale, ['description' => 'Baru'], 'Koreksi'))->toThrow(ConcurrencyConflict::class)
        ->and($ncr->refresh()->stage)->toBe(NcrStage::Containment);
});

test('severity and categories follow only an approved taxonomy', function (): void {
    expect(AccessFixtures::violatedRule(fn () => raiseNcr($this, ['severity' => 'major'])))->toBe('severity_not_approved');

    config(['quality.severities' => ['minor', 'major', 'critical'], 'quality.defect_categories' => ['DF-WEAVE', 'DF-COLOR']]);

    expect(AccessFixtures::violatedRule(fn () => raiseNcr($this)))->toBe('unknown_severity')
        ->and(AccessFixtures::violatedRule(fn () => raiseNcr($this, ['severity' => 'major', 'category_code' => 'DF-OTHER'])))->toBe('unknown_category')
        ->and(raiseNcr($this, ['severity' => 'major'])->severity)->toBe('major');

    config(['quality.severities' => ['minor', 'minor']]);

    expect(AccessFixtures::violatedRule(fn () => raiseNcr($this, ['source_reference' => 'INS-2', 'category_code' => 'DF-YARN', 'severity' => 'minor'])))->toBe('quality_rules_invalid');
});

test('a linked KPI names its published definition version, and corrections keep before and after', function (): void {
    $ncr = raiseNcr($this, ['kpi_code' => 'qc-reject']);

    expect($ncr->kpi_definition_version_id)->toBe($this->kpi->id)
        ->and($ncr->kpiVersion?->version_number)->toBe($this->kpi->version_number)
        ->and(AccessFixtures::violatedRule(fn () => raiseNcr($this, ['source_reference' => 'INS-2', 'category_code' => 'DF-YARN', 'kpi_code' => 'NOPE'])))->toBe('kpi_not_found');

    $corrected = $this->ncrs->correct(Harness::actor($this->qc), $this->qc, $ncr->id, $ncr->lock_version, ['quantity' => '18', 'kpi_code' => ''], 'Recount sortir');
    $event = $corrected->events()->where('action', 'corrected')->sole();

    expect($corrected->quantity)->toBe('18.000')
        ->and($corrected->kpi_definition_version_id)->toBeNull()
        ->and($event->changes)->toBe(['before' => ['quantity' => '20.000', 'kpi_definition_version_id' => $this->kpi->id], 'after' => ['quantity' => '18', 'kpi_definition_version_id' => null]])
        ->and($event->reason)->toBe('Recount sortir')
        ->and(AccessFixtures::violatedRule(fn () => $this->ncrs->correct(Harness::actor($this->qc), $this->qc, $ncr->id, $corrected->lock_version, ['quantity' => '18.000'], 'Sama')))->toBe('nothing_to_correct')
        ->and(fn () => $this->ncrs->correct(Harness::actor($this->woven), $this->woven, $ncr->id, $corrected->lock_version, ['quantity' => '1'], 'x'))->toThrow(AuthorizationException::class);
});

test('NCRs are read in scope, and restricted evidence only by those allowed', function (): void {
    $ncr = walkToVerification($this, raiseNcr($this));
    $closed = advanceNcr($this, $ncr, $this->qc, ['content' => 'Sesuai', 'evidence_reference' => 'Foto keluhan customer C-1', 'evidence_restricted' => true]);
    $verification = QualityNcrStage::query()->where('quality_ncr_id', $closed->id)->where('stage', NcrStage::Verification->value)->sole();
    $access = app(QualityAccess::class);

    expect($access->canRead($this->woven, $closed))->toBeTrue()
        ->and($access->canRead($this->qc, $closed))->toBeTrue()
        ->and($access->canRead($this->management, $closed))->toBeTrue()
        ->and($access->canRead($this->hr, $closed))->toBeTrue()
        ->and($access->canRead($this->auditor, $closed))->toBeTrue()
        ->and($access->canRead($this->flexo, $closed))->toBeFalse()
        ->and($access->canRead($this->sales, $closed))->toBeFalse()
        ->and(app(NcrQuery::class)->rows($this->flexo))->toBe([])
        ->and($access->canSeeRestrictedEvidence($this->qc, $closed, $verification))->toBeTrue()
        ->and($access->canSeeRestrictedEvidence($this->woven, $closed, $verification))->toBeTrue()
        ->and($access->canSeeRestrictedEvidence($this->auditor, $closed, $verification))->toBeTrue()
        ->and($access->canSeeRestrictedEvidence($this->management, $closed, $verification))->toBeFalse()
        ->and($access->canSeeRestrictedEvidence($this->hr, $closed, $verification))->toBeTrue()   // audit_report.view
        ->and($access->canSeeRestrictedEvidence($this->operator, $closed, $verification))->toBeFalse();

    // A PIC from another team reads the NCR they work on.
    $other = raiseNcr($this, ['source_reference' => 'INS-2', 'category_code' => 'DF-YARN', 'pic_user_id' => $this->flexo->id]);

    expect($access->canRead($this->flexo, $other))->toBeTrue()
        ->and(collect(app(NcrQuery::class)->rows($this->flexo))->map(fn (array $row): string => $row['ncr']->number)->all())->toBe([$other->number]);
});

test('inspection rows are checked against what was inspected', function (): void {
    $batch = Harness::stage($this->qc, BatchKind::Inspection, [
        'INS-10,final,2026-10-14 09:00,WO-100,P-1,L-7,,pcs,600,590,15,DF-1',
        'INS-11,final,2026-10-14 09:00,,P-1,L-7,,pcs,10,10,0,',
        'INS-12,incoming,2026-10-13 09:00,,YARN-1,,SUP-1,kg,10,10,0,',
        'INS-13,outgoing,2026-10-14 09:00,WO-1,,,,pcs,10,,,',
        'INS-14,in_process,2026-10-14 09:00,WO-1,,,,pcs,10,8,2,',
    ], 'PROD-QC', '2026-10-14', source: 'qms-fixture');
    $errors = implode("\n", $batch->errors ?? []);

    expect($batch->error_count)->toBe(4)
        ->and($errors)->toContain('accepted_qty ditambah rejected_qty melebihi inspected_qty')
        ->and($errors)->toContain('work_order wajib untuk inspeksi in_process dan final')
        ->and($errors)->toContain('inspected_at bukan tanggal inspeksi batch ini')
        ->and($errors)->toContain('inspection_stage harus incoming, in_process, atau final')
        ->and($batch->missing_reason_count)->toBe(1)
        ->and(fn () => Harness::stage($this->woven, BatchKind::Inspection, ['INS-20,final,2026-10-14 09:00,WO-1,,,,pcs,1,1,0,'], 'PROD-WOVEN', '2026-10-14'))->toThrow(AuthorizationException::class);
});

test('the NCR report lists NCRs in scope with traceability and filters by stage and team', function (): void {
    $first = raiseNcr($this, ['kpi_code' => 'QC-REJECT']);
    raiseNcr($this, ['source_type' => 'complaint', 'source_reference' => 'CMP-1', 'category_code' => 'DF-WEAVE', 'repeat_of' => $first->number, 'detected_at' => '2026-10-14 09:00', 'responsible_unit' => 'PROD-FLEXO']);
    advanceNcr($this, $first, $this->qc);
    $rows = collect(ReportHarness::rows($this->qc, 'quality_ncrs'))->keyBy('NCR');

    expect($rows['NCR-2026-0001'])->toMatchArray(['Tahap' => 'Containment', 'Sumber' => 'Inspeksi QC', 'Referensi sumber' => 'INS-1', 'Work order' => 'WO-100', 'Lot' => 'L-7', 'Kuantitas' => '20.000', 'Tenggat' => '2026-10-20', 'Lewat tenggat (hari)' => '0'])
        ->and($rows['NCR-2026-0001']['KPI terkait'])->toEndWith('v'.$this->kpi->version_number)
        ->and($rows['NCR-2026-0002']['Ulangan dari'])->toBe('NCR-2026-0001')
        ->and(ReportHarness::rows($this->qc, 'quality_ncrs', ['status' => 'containment']))->toHaveCount(1)
        ->and(ReportHarness::rows($this->woven, 'quality_ncrs'))->toHaveCount(1)
        ->and(ReportHarness::rows($this->management, 'quality_ncrs', ['unit' => 'PROD-FLEXO']))->toHaveCount(1)
        ->and(fn () => ReportHarness::rows($this->sales, 'quality_ncrs'))->toThrow(AuthorizationException::class);
});

test('review fixes: long text refused, zero rejects, subtree report filter, repeat cycles, later severity scale, numbering past 9999', function (): void {
    // Over-long evidence is refused, never cut.
    $ncr = raiseNcr($this);
    walkToVerification($this, $ncr);

    expect(AccessFixtures::violatedRule(fn () => advanceNcr($this, $ncr, $this->qc, ['content' => 'Sesuai', 'evidence_reference' => str_repeat('x', 501)])))->toBe('text_too_long');

    // An inspection with a defect code but no rejected quantity raises an NCR without quantity.
    Harness::load($this->qc, BatchKind::Inspection, ['INS-30,final,2026-10-14 09:30,WO-101,P-2,L-8,,pcs,50,50,0,DF-LABEL'], 'PROD-QC', '2026-10-14');
    $zero = raiseNcr($this, ['source_reference' => 'INS-30']);

    expect($zero->quantity)->toBeNull()
        ->and($zero->category_code)->toBe('DF-LABEL');

    // The report's unit filter covers the unit's subtree and QC's own NCRs.
    $flexo = raiseNcr($this, ['source_type' => 'internal', 'source_reference' => 'AUD-1', 'category_code' => 'DF-INK', 'responsible_unit' => 'PROD-FLEXO', 'detected_at' => '2026-10-14 09:00']);

    expect(ReportHarness::rows($this->management, 'quality_ncrs', ['unit' => 'PROD']))->toHaveCount(3)
        ->and(ReportHarness::rows($this->management, 'quality_ncrs', ['unit' => 'PROD-QC']))->toHaveCount(3)
        ->and(ReportHarness::rows($this->management, 'quality_ncrs', ['unit' => 'PROD-FLEXO']))->toHaveCount(1);

    // Repeat links never form a cycle.
    $this->ncrs->correct(Harness::actor($this->qc), $this->qc, $flexo->id, $flexo->lock_version, ['repeat_of' => $zero->number], 'Ulangan');

    expect(AccessFixtures::violatedRule(fn () => $this->ncrs->correct(Harness::actor($this->qc), $this->qc, $zero->id, $zero->refresh()->lock_version, ['repeat_of' => $flexo->number], 'Siklus')))->toBe('invalid_repeat');

    // A severity scale approved later does not block a category correction; an equal quantity is no change.
    config(['quality.severities' => ['minor', 'major']]);

    expect($this->ncrs->correct(Harness::actor($this->qc), $this->qc, $zero->id, $zero->refresh()->lock_version, ['category_code' => 'DF-PRINT'], 'Kategori salah')->category_code)->toBe('DF-PRINT')
        ->and(AccessFixtures::violatedRule(fn () => $this->ncrs->correct(Harness::actor($this->qc), $this->qc, $ncr->id, $ncr->refresh()->lock_version, ['quantity' => '20'], 'Sama')))->toBe('nothing_to_correct');

    // Numbers keep counting past 9999 in a year.
    DB::table('quality_ncrs')->where('id', $flexo->id)->update(['number' => 'NCR-2026-9999']);
    config(['quality.severities' => []]);
    $next = raiseNcr($this, ['source_type' => 'internal', 'source_reference' => 'AUD-2', 'category_code' => 'DF-INK', 'detected_at' => '2026-10-14 09:00']);
    $after = raiseNcr($this, ['source_type' => 'internal', 'source_reference' => 'AUD-3', 'category_code' => 'DF-INK', 'detected_at' => '2026-10-14 09:00']);

    expect([$next->number, $after->number])->toBe(['NCR-2026-10000', 'NCR-2026-10001'])
        ->and(AccessFixtures::violatedRule(fn () => raiseNcr($this, ['source_type' => 'internal', 'source_reference' => 'AUD-4', 'category_code' => 'DF-INK', 'responsible_unit' => 'NOPE', 'detected_at' => '2026-10-14 09:00'])))->toBe('unknown_unit');
});
