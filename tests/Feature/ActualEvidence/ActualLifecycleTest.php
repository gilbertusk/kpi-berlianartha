<?php

declare(strict_types=1);

use App\Modules\ActualEvidence\Application\ActualAccess;
use App\Modules\ActualEvidence\Application\ActualEntry;
use App\Modules\ActualEvidence\Application\ActualQuery;
use App\Modules\ActualEvidence\Application\ActualService;
use App\Modules\ActualEvidence\Application\EvidenceService;
use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Domain\EntryKind;
use App\Modules\ActualEvidence\Domain\Exceptions\ActualEntriesRejected;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Application\UnitScope;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\PeriodAssignment\Application\PeriodService;
use App\Modules\PeriodAssignment\Domain\DeadlineStage;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;
use Tests\Support\PeriodFixtures;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    Storage::fake('s3');
    Config::set('evidence.scanner', 'local-unscanned');

    $this->fixtures = new PeriodFixtures(new KpiFixtures);
    $this->actuals = app(ActualService::class);
    $this->hr = $this->fixtures->kpi->authorActor();
    $this->all = ActualAccess::everything();

    $this->staffUser = AccessFixtures::user('Staff Woven');
    AccessFixtures::employee($this->staffUser, 'PROD-WOVEN', number: 'W-001');
    AccessFixtures::grant($this->staffUser, Role::Employee);
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-002');
    $this->staff = new Actor($this->staffUser->id, Actor::system('test')->audit);

    $this->leaderUser = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    $this->leader = new Actor($this->leaderUser->id, Actor::system('test')->audit);

    $quality = $this->fixtures->kpi('AC-QUALITY', 'PROD-WOVEN', ['decimal_places' => 2, 'min_valid_value' => '0', 'max_valid_value' => '100']);
    $count = $this->fixtures->kpi('AC-REJECT', 'PROD-WOVEN', ['unit' => 'count', 'value_type' => 'integer', 'evidence_requirement' => 'none', 'evidence_description' => '']);
    $daily = $this->fixtures->kpi('AC-DAILY', 'PROD-WOVEN', ['unit' => 'pieces', 'value_type' => 'integer', 'frequency' => 'daily', 'evidence_requirement' => 'optional', 'evidence_description' => '']);
    $this->fixtures->publishedTemplate('T-AC-WOVEN', 'PROD-WOVEN', [$quality->id => '50', $count->id => '30', $daily->id => '20']);

    $this->period = $this->fixtures->inProgress(['deadlines' => ['target_setting' => '2026-10-20', 'actual_submission' => '2026-12-03']]);
    $this->target = fn (string $kpi, string $subject = 'W-001'): PeriodTarget => $this->fixtures->target($this->period, $kpi, $subject);

    $this->entry = fn (PeriodTarget $target, ?string $value, array $extra = []): ActualEntry => new ActualEntry(
        reference: $extra['reference'] ?? $target->public_id,
        targetId: $target->id,
        windowStart: $extra['from'] ?? '2026-11-01',
        windowEnd: $extra['to'] ?? '2026-11-30',
        value: $value,
        unit: $extra['unit'] ?? (string) $target->definitionVersion->unit,
        note: $extra['note'] ?? null,
        expectedLockVersion: $extra['lock'] ?? null,
        sourceSystem: $extra['system'] ?? null,
        sourceReference: $extra['ref'] ?? null,
    );

    $this->latest = fn (PeriodTarget $target): ?KpiActualVersion => KpiActualVersion::query()
        ->whereHas('actual', fn ($actual) => $actual->where('period_target_id', $target->id))
        ->orderByDesc('id')
        ->first();

    $this->rejected = function (callable $operation): array {
        try {
            $operation();
        } catch (ActualEntriesRejected $rejected) {
            return $rejected->rowErrors;
        }

        throw new RuntimeException('Expected ActualEntriesRejected.');
    };

    $this->pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
});

test('zero, not applicable, and missing are three different things', function (): void {
    $zero = ($this->target)('AC-REJECT');
    $notApplicable = ($this->target)('AC-REJECT', 'W-002');

    expect(($this->rejected)(fn () => $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [($this->entry)($notApplicable, null)]))[$notApplicable->public_id])
        ->toBe(['Aktual N/A wajib disertai catatan alasan.']);

    $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [
        ($this->entry)($zero, '0'),
        ($this->entry)($notApplicable, null, ['note' => 'Mesin dalam perbaikan sebulan penuh']),
    ]);

    $completeness = app(ActualQuery::class)->completeness($this->period, $this->all);

    expect(($this->latest)($zero)->entry_kind)->toBe(EntryKind::Value)
        ->and(($this->latest)($zero)->actual_value)->toBe('0.0000')
        ->and(($this->latest)($zero)->displayValue())->toBe('0')
        ->and(($this->latest)($notApplicable)->entry_kind)->toBe(EntryKind::NotApplicable)
        ->and(($this->latest)($notApplicable)->actual_value)->toBeNull()
        ->and(($this->latest)($notApplicable)->displayValue())->toBe('N/A')
        // 6 required targets, 2 with an entry: the other 4 are missing.
        ->and($completeness)->toMatchArray(['targets' => 6, 'missing' => 4, 'draft' => 2, 'entries' => 2])
        ->and(fn () => DB::transaction(fn () => DB::table('kpi_actual_versions')->where('id', ($this->latest)($zero)->id)->update(['actual_value' => null])))
        ->toThrow(QueryException::class);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The value/kind CHECK constraint exists on PostgreSQL only.');

test('zero and not applicable are stored differently and neither counts as missing', function (): void {
    $zero = ($this->target)('AC-REJECT');
    $notApplicable = ($this->target)('AC-REJECT', 'W-002');

    $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [
        ($this->entry)($zero, '0'),
        ($this->entry)($notApplicable, ' ', ['note' => 'Mesin dalam perbaikan']),
    ]);

    expect(($this->latest)($zero)->actual_value)->toBe('0.0000')
        ->and(($this->latest)($notApplicable)->entry_kind)->toBe(EntryKind::NotApplicable)
        ->and(app(ActualQuery::class)->completeness($this->period, $this->all))->toMatchArray(['targets' => 6, 'missing' => 4, 'draft' => 2]);
});

test('value boundary, precision, and unit follow the KPI definition and a batch is all or nothing', function (): void {
    $quality = ($this->target)('AC-QUALITY');
    $count = ($this->target)('AC-REJECT');
    $other = ($this->target)('AC-QUALITY', 'W-002');

    $errors = ($this->rejected)(fn () => $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [
        ($this->entry)($quality, '100.01'),
        ($this->entry)($count, '3.5'),
        ($this->entry)($other, '97.555'),
        ($this->entry)(($this->target)('AC-REJECT', 'W-002'), '4', ['unit' => 'pieces', 'reference' => 'unit']),
        ($this->entry)(($this->target)('AC-DAILY'), '120', ['from' => '2026-11-02', 'to' => '2026-11-02', 'reference' => 'valid']),
    ]));

    expect($errors[$quality->public_id][0])->toContain('Nilai aktual lebih besar dari nilai input maksimum KPI (100)')
        ->and($errors[$count->public_id])->toBe(['KPI ini hanya menerima bilangan bulat.'])
        ->and($errors[$other->public_id])->toBe(['KPI ini menerima paling banyak 2 angka di belakang koma.'])
        ->and($errors['unit'])->toBe(['Satuan aktual (pieces) tidak sesuai dengan satuan KPI (count).'])
        ->and($errors)->not->toHaveKey('valid')
        ->and(KpiActual::query()->count())->toBe(0);

    $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [($this->entry)($quality, '100'), ($this->entry)($other, '0')]);

    expect(KpiActualVersion::query()->orderBy('id')->pluck('actual_value')->all())->toBe(['100.0000', '0.0000']);
});

test('observation windows stay inside the period, follow the frequency, and never overlap', function (): void {
    $quality = ($this->target)('AC-QUALITY');
    $daily = ($this->target)('AC-DAILY');
    $save = fn (ActualEntry ...$entries) => ($this->rejected)(fn () => $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, array_values($entries)));

    expect($save(($this->entry)($quality, '90', ['from' => '2026-10-31', 'to' => '2026-11-30']))[$quality->public_id][0])->toContain('harus berada di dalam periode')
        ->and($save(($this->entry)($quality, '90', ['from' => '2026-11-10', 'to' => '2026-11-01']))[$quality->public_id][0])->toContain('tidak boleh sebelum')
        ->and($save(($this->entry)($quality, '90', ['from' => '2026-11-31', 'to' => '2026-11-31']))[$quality->public_id][0])->toContain('tanggal yang valid')
        ->and($save(($this->entry)($daily, '100', ['from' => '2026-11-01', 'to' => '2026-11-02']))[$daily->public_id][0])->toContain('KPI harian diisi per tanggal')
        ->and($save(($this->entry)($quality, '90', ['from' => '2026-11-01', 'to' => '2026-11-15', 'reference' => 'a']), ($this->entry)($quality, '91', ['from' => '2026-11-15', 'to' => '2026-11-30', 'reference' => 'b']))['b'][0])->toContain('lebih dari satu kali');

    $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [
        ($this->entry)($quality, '90', ['from' => '2026-11-01', 'to' => '2026-11-15', 'reference' => 'a']),
        ($this->entry)($quality, '92', ['from' => '2026-11-16', 'to' => '2026-11-30', 'reference' => 'b']),
        ($this->entry)($daily, '100', ['from' => '2026-11-02', 'to' => '2026-11-02', 'reference' => 'c']),
        ($this->entry)($daily, '110', ['from' => '2026-11-03', 'to' => '2026-11-03', 'reference' => 'd']),
    ]);

    expect(KpiActual::query()->count())->toBe(4)
        ->and($save(($this->entry)($quality, '95', ['from' => '2026-11-10', 'to' => '2026-11-20']))[$quality->public_id][0])->toContain('bertumpang tindih dengan aktual lain');
});

test('an actual goes from draft through submit to verification by the team leader, with evidence where required', function (): void {
    $quality = ($this->target)('AC-QUALITY');
    $staffAccess = ActualAccess::for(app(AccessControl::class), $this->staffUser, Capability::ActualEnter);
    $leaderAccess = ActualAccess::for(app(AccessControl::class), $this->leaderUser, Capability::ActualVerify);

    $this->actuals->saveDrafts($this->staff, $this->period->id, $staffAccess, [($this->entry)($quality, '96.5')]);
    $actual = KpiActual::query()->firstOrFail();

    expect(AccessFixtures::violatedRule(fn () => $this->actuals->submit($this->staff, $this->period->id, $staffAccess)))->toBe('evidence_required');

    app(EvidenceService::class)->attach($this->staff, $actual->id, 'laporan-qc.pdf', $this->pdf);

    expect($this->actuals->submit($this->staff, $this->period->id, $staffAccess))->toBe(1)
        ->and(($this->rejected)(fn () => $this->actuals->saveDrafts($this->staff, $this->period->id, $staffAccess, [($this->entry)($quality, '99')]))[$quality->public_id][0])->toContain('menunggu verifikasi')
        // The employee who entered it cannot verify it, whatever scope is passed.
        ->and(AccessFixtures::violatedRule(fn () => $this->actuals->verify($this->staff, $this->period->id, $this->all)))->toBe('self_review');

    $verified = $this->actuals->verify($this->leader, $this->period->id, $leaderAccess);
    $version = ($this->latest)($quality);

    expect($verified)->toBe(['verified' => 1, 'skipped_self' => 0, 'blocked_evidence' => 0])
        ->and($version->status)->toBe(ActualStatus::Verified)
        ->and($version->verified_by_user_id)->toBe($this->leaderUser->id)
        ->and($version->submitted_by_user_id)->toBe($this->staffUser->id)
        ->and(OutboxMessage::query()->whereIn('event_type', ['ActualSubmitted', 'ActualVerified'])->where('aggregate_id', $actual->public_id)->orderBy('id')->pluck('event_type')->all())->toBe(['ActualSubmitted', 'ActualVerified'])
        ->and(AuditLog::query()->where('entity_type', 'kpi_actual')->where('entity_id', $actual->public_id)->orderBy('id')->pluck('action')->all())
        ->toBe(['actual.draft_created', 'evidence.attached', 'actual.submitted', 'actual.verified']);

    // Final: no change by service or by SQL.
    expect(($this->rejected)(fn () => $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [($this->entry)($quality, '50')]))[$quality->public_id][0])->toContain('sudah terverifikasi')
        ->and(fn () => DB::transaction(fn () => DB::table('kpi_actual_versions')->where('id', $version->id)->update(['actual_value' => '50.0000'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('kpi_actual_versions')->where('id', $version->id)->update(['verified_by_user_id' => $this->staffUser->id])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('kpi_actual_versions')->where('id', $version->id)->delete()))->toThrow(QueryException::class)
        ->and(AccessFixtures::violatedRule(fn () => app(EvidenceService::class)->attach($this->staff, $actual->id, 'lain.pdf', $this->pdf.'x')))->toBe('evidence_locked');
});

test('a rejected actual returns to its owner, is corrected, and submitted again', function (): void {
    $count = ($this->target)('AC-REJECT');
    $this->actuals->saveDrafts($this->staff, $this->period->id, $this->all, [($this->entry)($count, '7')]);
    $this->actuals->submit($this->staff, $this->period->id, $this->all);
    $submitted = ($this->latest)($count);

    expect(AccessFixtures::violatedRule(fn () => $this->actuals->reject($this->leader, $submitted->id, $submitted->lock_version, $this->all, ' ')))->toBe('reason_required');

    $rejected = $this->actuals->reject($this->leader, $submitted->id, $submitted->lock_version, $this->all, 'Tidak cocok dengan laporan QC');

    expect($rejected->status)->toBe(ActualStatus::Rejected)
        ->and($rejected->reject_reason)->toBe('Tidak cocok dengan laporan QC');

    $this->actuals->saveDrafts($this->staff, $this->period->id, $this->all, [($this->entry)($count, '9', ['lock' => $rejected->lock_version])]);
    $this->actuals->submit($this->staff, $this->period->id, $this->all);
    $this->actuals->verify($this->leader, $this->period->id, $this->all);

    expect(($this->latest)($count)->status)->toBe(ActualStatus::Verified)
        ->and(($this->latest)($count)->actual_value)->toBe('9.0000')
        ->and(KpiActualVersion::query()->count())->toBe(1);
});

test('a concurrent edit is refused instead of overwriting the other change', function (): void {
    $count = ($this->target)('AC-REJECT');
    $this->actuals->saveDrafts($this->staff, $this->period->id, $this->all, [($this->entry)($count, '7')]);
    $seen = ($this->latest)($count)->lock_version;

    $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [($this->entry)($count, '8', ['lock' => $seen])]);

    $stale = ($this->rejected)(fn () => $this->actuals->saveDrafts($this->staff, $this->period->id, $this->all, [($this->entry)($count, '9', ['lock' => $seen])]));
    $unseen = ($this->rejected)(fn () => $this->actuals->saveDrafts($this->staff, $this->period->id, $this->all, [($this->entry)($count, '9', ['lock' => 0])]));

    expect($stale[$count->public_id][0])->toContain('baru saja diubah pengguna lain')
        ->and($unseen[$count->public_id][0])->toContain('baru saja diubah pengguna lain')
        ->and(($this->latest)($count)->actual_value)->toBe('8.0000');
});

test('late entry follows the business date in Asia/Jakarta and needs a reason', function (): void {
    $count = ($this->target)('AC-REJECT');
    $other = ($this->target)('AC-REJECT', 'W-002');

    Date::setTestNow(CarbonImmutable::parse('2026-12-03 16:59:59', 'UTC'));
    $this->actuals->saveDrafts($this->staff, $this->period->id, $this->all, [($this->entry)($count, '7')]);

    Date::setTestNow(CarbonImmutable::parse('2026-12-03 17:00:00', 'UTC'));

    expect(($this->latest)($count)->is_late)->toBeFalse()
        ->and(AccessFixtures::violatedRule(fn () => $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [($this->entry)($other, '3')])))->toBe('late_reason_required')
        ->and(AccessFixtures::violatedRule(fn () => $this->actuals->submit($this->staff, $this->period->id, $this->all)))->toBe('late_reason_required');

    $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [($this->entry)($other, '3')], 'Laporan produksi terlambat diterima');
    $this->actuals->submit($this->staff, $this->period->id, $this->all, null, 'Cuti sakit');

    expect(($this->latest)($other)->is_late)->toBeTrue()
        ->and(($this->latest)($other)->late_reason)->toBe('Laporan produksi terlambat diterima')
        ->and(($this->latest)($count)->is_late)->toBeTrue()
        ->and(($this->latest)($count)->late_reason)->toBe('Cuti sakit')
        ->and(app(ActualQuery::class)->completeness($this->period, $this->all)['late'])->toBe(2);
});

test('an employee enters only their own actuals and a team leader verifies only their team', function (): void {
    $own = ($this->target)('AC-REJECT');
    $colleague = ($this->target)('AC-REJECT', 'W-002');
    $staffAccess = ActualAccess::for(app(AccessControl::class), $this->staffUser, Capability::ActualEnter);

    $errors = ($this->rejected)(fn () => $this->actuals->saveDrafts($this->staff, $this->period->id, $staffAccess, [($this->entry)($own, '1'), ($this->entry)($colleague, '2')]));

    expect($errors)->toHaveKey($colleague->public_id)
        ->and($errors)->not->toHaveKey($own->public_id)
        ->and($staffAccess->units->reachesOthers())->toBeFalse();

    $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [($this->entry)($own, '1'), ($this->entry)($colleague, '2')]);
    $this->actuals->submit($this->hr, $this->period->id, $this->all);

    $otherLeader = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-QC');
    $otherAccess = ActualAccess::for(app(AccessControl::class), $otherLeader, Capability::ActualVerify);

    expect(AccessFixtures::violatedRule(fn () => $this->actuals->verify(new Actor($otherLeader->id, Actor::system('test')->audit), $this->period->id, $otherAccess)))->toBe('nothing_to_verify')
        ->and(ActualAccess::for(app(AccessControl::class), $this->leaderUser, Capability::ActualEnter)->isEmpty())->toBeTrue()
        ->and(KpiActualVersion::query()->where('status', 'submitted')->count())->toBe(2);
});

test('one source record feeds one actual of a target', function (): void {
    $daily = ($this->target)('AC-DAILY');

    $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [($this->entry)($daily, '100', ['from' => '2026-11-02', 'to' => '2026-11-02', 'system' => 'MES', 'ref' => 'WO-1001'])]);

    $duplicate = ($this->rejected)(fn () => $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [($this->entry)($daily, '100', ['from' => '2026-11-03', 'to' => '2026-11-03', 'system' => 'MES', 'ref' => 'WO-1001'])]));
    $half = ($this->rejected)(fn () => $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [($this->entry)($daily, '100', ['from' => '2026-11-04', 'to' => '2026-11-04', 'system' => 'MES'])]));

    expect($duplicate[$daily->public_id][0])->toContain('Record sumber ini sudah dipakai')
        ->and($half[$daily->public_id][0])->toContain('harus diisi bersamaan')
        ->and(KpiActual::query()->firstOrFail()->only(['source_system', 'source_reference']))->toBe(['source_system' => 'MES', 'source_reference' => 'WO-1001']);
});

test('actuals are accepted only while the period is in progress', function (): void {
    $opened = $this->fixtures->opened(['code' => 'P-2026-12', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31']);
    $target = $this->fixtures->target($opened, 'AC-REJECT', 'W-001');

    expect(AccessFixtures::violatedRule(fn () => $this->actuals->saveDrafts($this->hr, $opened->id, $this->all, [($this->entry)($target, '1', ['from' => '2026-12-01', 'to' => '2026-12-31'])])))->toBe('period_not_accepting_actuals');

    DB::table('assessment_periods')->where('id', $this->period->id)->update(['status' => 'review']);

    expect(AccessFixtures::violatedRule(fn () => $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [($this->entry)(($this->target)('AC-REJECT'), '1')])))->toBe('period_not_accepting_actuals')
        ->and(app(PeriodService::class)->blockersFor($this->period, PeriodStatus::InProgress))->toBe([])
        ->and(DeadlineStage::ActualSubmission->value)->toBe('actual_submission');
});

test('rejecting respects scope and independence, and a bulk submit takes only the drafts the actor wrote', function (): void {
    $own = ($this->target)('AC-REJECT');
    $colleague = ($this->target)('AC-REJECT', 'W-002');
    $this->actuals->saveDrafts($this->staff, $this->period->id, $this->all, [($this->entry)($own, '1')]);
    $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [($this->entry)($colleague, '2')]);

    // HR submits its own entry only; the employee's draft stays editable.
    expect($this->actuals->submit($this->hr, $this->period->id, $this->all, null, null, true))->toBe(1)
        ->and(($this->latest)($own)->status)->toBe(ActualStatus::Draft)
        ->and(($this->latest)($colleague)->status)->toBe(ActualStatus::Submitted);

    $submitted = ($this->latest)($colleague);
    $elsewhere = new ActualAccess(new UnitScope(false, [AccessFixtures::unit('ACC-AR')->id], false), null);

    expect(AccessFixtures::violatedRule(fn () => $this->actuals->reject($this->leader, $submitted->id, $submitted->lock_version, $elsewhere, 'Di luar scope')))->toBe('actual_out_of_scope')
        ->and(AccessFixtures::violatedRule(fn () => $this->actuals->reject($this->hr, $submitted->id, $submitted->lock_version, $this->all, 'Menolak isian sendiri')))->toBe('self_review')
        ->and(($this->latest)($colleague)->status)->toBe(ActualStatus::Submitted);
});

test('the identity of an actual is immutable and its source reference is never replaced', function (): void {
    $daily = ($this->target)('AC-DAILY');
    $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [($this->entry)($daily, '100', ['from' => '2026-11-02', 'to' => '2026-11-02'])]);
    $actual = KpiActual::query()->firstOrFail();

    $mismatch = ($this->rejected)(fn () => $this->actuals->saveDrafts($this->hr, $this->period->id, $this->all, [($this->entry)($daily, '120', ['from' => '2026-11-02', 'to' => '2026-11-02', 'system' => 'MES', 'ref' => 'WO-9'])]));

    expect($mismatch[$daily->public_id][0])->toContain('referensi sumber yang berbeda')
        ->and(fn () => DB::transaction(fn () => DB::table('kpi_actuals')->where('id', $actual->id)->update(['window_end' => '2026-11-03'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('kpi_actuals')->where('id', $actual->id)->delete()))->toThrow(QueryException::class);
});
