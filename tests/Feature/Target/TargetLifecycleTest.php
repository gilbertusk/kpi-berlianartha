<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Organization\Application\EmployeeService;
use App\Modules\PeriodAssignment\Application\PeriodService;
use App\Modules\PeriodAssignment\Domain\DeadlineStage;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Domain\Exceptions\InvalidStateTransition;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use App\Modules\Target\Application\TargetEntry;
use App\Modules\Target\Application\TargetQuery;
use App\Modules\Target\Application\TargetService;
use App\Modules\Target\Domain\Exceptions\TargetEntriesRejected;
use App\Modules\Target\Domain\TargetStatus;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTarget;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTargetVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;
use Tests\Support\PeriodFixtures;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->fixtures = new PeriodFixtures(new KpiFixtures);
    $this->targets = app(TargetService::class);
    $this->author = $this->fixtures->kpi->authorActor();
    $this->reviewer = $this->fixtures->kpi->reviewerActor();
    $this->all = PeriodFixtures::everything();

    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-001');
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-002');

    $this->output = $this->fixtures->kpi('TG-OUTPUT', 'PROD-WOVEN', ['unit' => 'pieces', 'value_type' => 'integer']);
    $this->quality = $this->fixtures->kpi('TG-QUALITY', 'PROD-WOVEN', ['decimal_places' => 2, 'min_valid_value' => '0', 'max_valid_value' => '100']);
    $this->teamKpi = $this->fixtures->kpi('TG-TEAM', 'PROD-WOVEN', ['evaluation_subject' => 'team']);

    $this->fixtures->publishedTemplate('T-TG-WOVEN', 'PROD-WOVEN', [$this->output->id => '50', $this->quality->id => '30', $this->teamKpi->id => '20']);

    $this->period = $this->fixtures->opened(['deadlines' => ['target_setting' => '2026-11-05']]);
    $this->latest = fn (PeriodTarget $target): ?PeriodTargetVersion => PeriodTargetVersion::query()->where('period_target_id', $target->id)->orderByDesc('version_number')->first();

    $this->rejected = function (callable $operation): array {
        try {
            $operation();
        } catch (TargetEntriesRejected $rejected) {
            return $rejected->rowErrors;
        }

        throw new RuntimeException('Expected TargetEntriesRejected.');
    };
});

test('opening creates one target per KPI at the level the KPI is assessed on', function (): void {
    $slots = PeriodTarget::query()->where('assessment_period_id', $this->period->id)->orderBy('kpi_code')->orderBy('subject_code')->get();

    expect($slots->map(fn (PeriodTarget $slot): string => "{$slot->kpi_code}|{$slot->subject_type->value}|{$slot->subject_code}")->all())->toBe([
        'TG-OUTPUT|employee|W-001',
        'TG-OUTPUT|employee|W-002',
        'TG-QUALITY|employee|W-001',
        'TG-QUALITY|employee|W-002',
        // A team-level KPI on two scorecards is a single team target.
        'TG-TEAM|unit|PROD-WOVEN',
    ])
        ->and($slots->every(fn (PeriodTarget $slot): bool => $slot->is_required && $slot->unit_code === 'PROD-WOVEN'))->toBeTrue()
        ->and(fn () => DB::transaction(fn () => DB::table('period_targets')->where('id', $slots[0]->id)->update(['is_required' => false])))->toThrow(QueryException::class);
});

test('a KPI limited to a position yields targets only for that position', function (): void {
    $operatorKpi = $this->fixtures->kpi('TG-OPERATOR', 'PROD-QC');
    $shared = $this->fixtures->kpi('TG-QC-SHARED', 'PROD-QC');
    $operator = AccessFixtures::employee(null, 'PROD-QC', number: 'Q-001');
    AccessFixtures::employee(null, 'PROD-QC', number: 'Q-002');
    app(EmployeeService::class)->transferHome($this->author, $operator->id, $operator->lock_version, AccessFixtures::unit('PROD-QC')->id, '2026-10-20', 'Operator', 'Penetapan jabatan');
    $this->fixtures->publishedTemplate('T-TG-QC', 'PROD-QC', [$operatorKpi->id => '40', $shared->id => '60'], positionRules: [$operatorKpi->id => ['operator']]);

    $december = $this->fixtures->opened(['code' => 'P-2026-12', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31']);

    expect(PeriodTarget::query()->where('assessment_period_id', $december->id)->where('kpi_code', 'TG-OPERATOR')->pluck('subject_code')->all())->toBe(['Q-001'])
        ->and(PeriodTarget::query()->where('assessment_period_id', $december->id)->where('kpi_code', 'TG-QC-SHARED')->count())->toBe(2);
});

test('a target with a wrong unit, precision, or range is rejected and nothing of the batch is saved', function (): void {
    $output = $this->fixtures->target($this->period, 'TG-OUTPUT', 'W-001');
    $quality = $this->fixtures->target($this->period, 'TG-QUALITY', 'W-001');
    $other = $this->fixtures->target($this->period, 'TG-QUALITY', 'W-002');

    $errors = ($this->rejected)(fn () => $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [
        $this->fixtures->entry($output, '1200.5'),
        $this->fixtures->entry($quality, '98.555'),
        new TargetEntry('wrong-unit', $other->id, '90', 'pieces'),
        $this->fixtures->entry($this->fixtures->target($this->period, 'TG-OUTPUT', 'W-002'), '1500'),
    ]));

    expect($errors[$output->public_id])->toBe(['KPI ini hanya menerima bilangan bulat.'])
        ->and($errors[$quality->public_id])->toBe(['KPI ini menerima paling banyak 2 angka di belakang koma.'])
        ->and($errors['wrong-unit'])->toBe(['Satuan target (pieces) tidak sesuai dengan satuan KPI (percent).'])
        ->and(array_keys($errors))->toHaveCount(3)
        // The fourth entry was valid, but the batch is all-or-nothing.
        ->and(PeriodTargetVersion::query()->count())->toBe(0);

    $range = ($this->rejected)(fn () => $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($quality, '100.01')]));

    expect($range[$quality->public_id][0])->toContain('nilai input maksimum KPI (100)');
});

test('a draft is saved, changed with optimistic locking, submitted, and approved by someone else', function (): void {
    $target = $this->fixtures->target($this->period, 'TG-QUALITY', 'W-001');

    $result = $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($target, '97.5')]);
    $draft = ($this->latest)($target);

    expect($result)->toBe(['created' => 1, 'updated' => 0, 'unchanged' => 0])
        ->and($draft->status)->toBe(TargetStatus::Draft)
        ->and($draft->target_value)->toBe('97.5000')
        ->and($draft->unit)->toBe('percent')
        ->and($draft->after_cutoff)->toBeFalse();

    $again = $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($target, '97.50', expectedLock: $draft->lock_version)]);
    $updated = $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($target, '98', expectedLock: $draft->lock_version)]);
    $stale = ($this->rejected)(fn () => $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($target, '99', expectedLock: $draft->lock_version)]));

    expect($again)->toBe(['created' => 0, 'updated' => 0, 'unchanged' => 1])
        ->and($updated)->toBe(['created' => 0, 'updated' => 1, 'unchanged' => 0])
        ->and($stale[$target->public_id][0])->toContain('baru saja diubah pengguna lain')
        ->and(($this->latest)($target)->target_value)->toBe('98.0000');

    expect($this->targets->submit($this->author, $this->period->id, $this->all))->toBe(1)
        ->and(AccessFixtures::violatedRule(fn () => $this->targets->approve($this->author, $this->period->id, $this->all)))->toBe('self_review')
        ->and(($this->latest)($target)->status)->toBe(TargetStatus::Submitted)
        ->and(($this->rejected)(fn () => $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($target, '90')]))[$target->public_id][0])->toContain('menunggu persetujuan');

    $approved = $this->targets->approve($this->reviewer, $this->period->id, $this->all);
    $final = ($this->latest)($target);

    expect($approved)->toBe(['approved' => 1, 'skipped' => 0])
        ->and($final->status)->toBe(TargetStatus::Approved)
        ->and($final->approved_by_user_id)->toBe($this->fixtures->kpi->reviewer->id)
        ->and($final->submitted_by_user_id)->toBe($this->fixtures->kpi->author->id)
        ->and(OutboxMessage::query()->where('event_type', 'TargetApproved')->where('aggregate_id', $target->public_id)->count())->toBe(1)
        ->and(AuditLog::query()->where('entity_type', 'period_target')->where('entity_id', $target->public_id)->orderBy('id')->pluck('action')->all())
        ->toBe(['target.draft_created', 'target.draft_updated', 'target.submitted', 'target.approved']);
});

test('an approver never approves the target of their own employee record', function (): void {
    $ownEmployee = AccessFixtures::employee($this->fixtures->kpi->reviewer, 'PROD-WOVEN', number: 'W-HR');
    $december = $this->fixtures->opened(['code' => 'P-2026-12', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31']);
    $own = $this->fixtures->target($december, 'TG-QUALITY', 'W-HR');
    $other = $this->fixtures->target($december, 'TG-QUALITY', 'W-001');

    $this->targets->saveDrafts($this->author, $december->id, $this->all, [$this->fixtures->entry($own, '90'), $this->fixtures->entry($other, '91')]);
    $this->targets->submit($this->author, $december->id, $this->all);

    expect($this->targets->approve($this->reviewer, $december->id, $this->all))->toBe(['approved' => 1, 'skipped' => 1])
        ->and(($this->latest)($own)->status)->toBe(TargetStatus::Submitted)
        ->and(($this->latest)($other)->status)->toBe(TargetStatus::Approved)
        ->and($ownEmployee->user_id)->toBe($this->fixtures->kpi->reviewer->id);
});

test('a returned target goes back to draft with a reason', function (): void {
    $target = $this->fixtures->target($this->period, 'TG-QUALITY', 'W-001');
    $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($target, '97')]);
    $this->targets->submit($this->author, $this->period->id, $this->all);
    $submitted = ($this->latest)($target);

    expect(AccessFixtures::violatedRule(fn () => $this->targets->returnToDraft($this->reviewer, $submitted->id, $submitted->lock_version, '')))->toBe('reason_required');

    $returned = $this->targets->returnToDraft($this->reviewer, $submitted->id, $submitted->lock_version, 'Angka tidak sesuai rapat target');

    expect($returned->status)->toBe(TargetStatus::Draft)
        ->and($returned->return_reason)->toBe('Angka tidak sesuai rapat target')
        ->and(fn () => $this->targets->returnToDraft($this->reviewer, $returned->id, $returned->lock_version, 'Lagi'))->toThrow(InvalidStateTransition::class);
});

test('a final target cannot be overwritten: not by a save, not by direct SQL, only by a reasoned revision', function (): void {
    $target = $this->fixtures->target($this->period, 'TG-QUALITY', 'W-001');
    $this->fixtures->approvedTarget($this->period, $target, '97');
    $final = ($this->latest)($target);

    $direct = ($this->rejected)(fn () => $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($target, '80')]));
    $noReason = ($this->rejected)(fn () => $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($target, '80')], null, true));
    $same = ($this->rejected)(fn () => $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($target, '97.0')], 'Tidak berubah', true));

    expect($direct[$target->public_id][0])->toContain('sudah final')
        ->and($noReason[$target->public_id][0])->toContain('wajib disertai alasan')
        ->and($same[$target->public_id][0])->toContain('sama dengan target final');

    expect(fn () => DB::transaction(fn () => DB::table('period_target_versions')->where('id', $final->id)->update(['target_value' => '80.0000'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('period_target_versions')->where('id', $final->id)->update(['status' => 'draft'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('period_target_versions')->where('id', $final->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('period_target_versions')->insert([
            'public_id' => '01KTESTTARGETVERSION000000',
            'period_target_id' => $target->id,
            'version_number' => 9,
            'status' => 'approved',
            'target_value' => '1.0000',
            'unit' => 'percent',
            'source' => 'manual',
            'lock_version' => 1,
        ])))->toThrow(QueryException::class);

    $this->fixtures->approvedTarget($this->period, $target, '95', 'Kapasitas mesin turun', true);
    $versions = PeriodTargetVersion::query()->where('period_target_id', $target->id)->orderBy('version_number')->get();

    expect($versions)->toHaveCount(2)
        ->and($versions[0]->target_value)->toBe('97.0000')
        ->and($versions[0]->status)->toBe(TargetStatus::Approved)
        ->and($versions[1]->target_value)->toBe('95.0000')
        ->and($versions[1]->previous_version_id)->toBe($versions[0]->id)
        ->and($versions[1]->change_reason)->toBe('Kapasitas mesin turun')
        ->and(AuditLog::query()->where('action', 'target.revision_drafted')->where('entity_id', $target->public_id)->value('reason'))->toBe('Kapasitas mesin turun');
});

test('the target cut-off follows the business date in Asia/Jakarta', function (): void {
    $target = $this->fixtures->target($this->period, 'TG-QUALITY', 'W-001');
    $other = $this->fixtures->target($this->period, 'TG-QUALITY', 'W-002');

    // 2026-11-05 23:59:59 WIB: the deadline date is still open.
    Date::setTestNow(CarbonImmutable::parse('2026-11-05 16:59:59', 'UTC'));
    $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($target, '97')]);

    // One second later it is 2026-11-06 00:00:00 WIB although still 5 November in UTC.
    Date::setTestNow(CarbonImmutable::parse('2026-11-05 17:00:00', 'UTC'));

    expect(($this->latest)($target)->after_cutoff)->toBeFalse()
        ->and($this->targets->isAfterCutoff($this->period->fresh()))->toBeTrue()
        ->and(AccessFixtures::violatedRule(fn () => $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($other, '96')])))->toBe('cutoff_reason_required')
        ->and(($this->latest)($other))->toBeNull();

    $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($other, '96')], 'Data HR terlambat');
    $late = ($this->latest)($other);

    expect($late->after_cutoff)->toBeTrue()
        ->and($late->change_reason)->toBe('Data HR terlambat');

    // Moving the deadline (with a reason) reopens normal entry.
    $period = app(PeriodService::class)->rescheduleDeadline($this->author, $this->period->id, $this->period->fresh()->lock_version, DeadlineStage::TargetSetting, '2026-11-06', 'Perpanjangan disetujui HR');

    expect($this->targets->isAfterCutoff($period))->toBeFalse();
});

test('once the period is in progress every target change needs a reason, and later statuses reject changes', function (): void {
    foreach (PeriodTarget::query()->where('assessment_period_id', $this->period->id)->get() as $slot) {
        $this->fixtures->approvedTarget($this->period, $slot, '90');
    }

    $period = app(PeriodService::class)->startProgress($this->author, $this->period->id, $this->period->fresh()->lock_version);
    $target = $this->fixtures->target($period, 'TG-QUALITY', 'W-001');

    expect(AccessFixtures::violatedRule(fn () => $this->targets->saveDrafts($this->author, $period->id, $this->all, [$this->fixtures->entry($target, '85')], null, true)))->toBe('cutoff_reason_required');

    $this->targets->saveDrafts($this->author, $period->id, $this->all, [$this->fixtures->entry($target, '85')], 'Revisi setelah audit mesin', true);

    expect(($this->latest)($target)->after_cutoff)->toBeTrue();

    DB::table('assessment_periods')->where('id', $period->id)->update(['status' => 'review']);

    expect(AccessFixtures::violatedRule(fn () => $this->targets->saveDrafts($this->author, $period->id, $this->all, [$this->fixtures->entry($target, '80')], 'Terlambat', true)))->toBe('period_not_accepting_targets')
        ->and(AccessFixtures::violatedRule(fn () => $this->targets->submit($this->author, $period->id, $this->all)))->toBe('period_not_accepting_targets');
});

test('bulk operations never touch targets outside the actor scope', function (): void {
    AccessFixtures::employee(null, 'ACC-AR', number: 'AR-001');
    $dso = $this->fixtures->kpi('TG-DSO', 'ACC-AR');
    $this->fixtures->publishedTemplate('T-TG-AR', 'ACC-AR', [$dso->id => '100']);
    $december = $this->fixtures->opened(['code' => 'P-2026-12', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31']);

    $hrAccounting = AccessFixtures::userWithRole(Role::HrPerformance, 'ACC');
    $actor = new Actor($hrAccounting->id, Actor::system('test')->audit);
    $scope = app(AccessControl::class)->unitScope($hrAccounting, Capability::TargetManage);
    $woven = $this->fixtures->target($december, 'TG-QUALITY', 'W-001');
    $accounting = $this->fixtures->target($december, 'TG-DSO', 'AR-001');

    $errors = ($this->rejected)(fn () => $this->targets->saveDrafts($actor, $december->id, $scope, [$this->fixtures->entry($woven, '90'), $this->fixtures->entry($accounting, '45')]));

    expect($errors)->toHaveKey($woven->public_id)
        ->and($errors)->not->toHaveKey($accounting->public_id);

    $this->targets->saveDrafts($this->author, $december->id, $this->all, [$this->fixtures->entry($woven, '90'), $this->fixtures->entry($accounting, '45')]);

    expect($this->targets->submit($actor, $december->id, $scope))->toBe(1)
        ->and(($this->latest)($woven)->status)->toBe(TargetStatus::Draft)
        ->and(($this->latest)($accounting)->status)->toBe(TargetStatus::Submitted);
});

test('the completeness indicator counts required targets by state', function (): void {
    $query = app(TargetQuery::class);
    $first = $this->fixtures->target($this->period, 'TG-QUALITY', 'W-001');
    $second = $this->fixtures->target($this->period, 'TG-QUALITY', 'W-002');
    $third = $this->fixtures->target($this->period, 'TG-OUTPUT', 'W-001');

    $this->fixtures->approvedTarget($this->period, $first, '97');
    $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($second, '96'), $this->fixtures->entry($third, '1200')]);
    $this->targets->submit($this->author, $this->period->id, $this->all, [$third->id]);
    $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($first, '95')], 'Revisi', true);

    $completeness = $query->completeness($this->period, $this->all);
    $states = collect($query->matrix($this->period, $this->all, null, null, '', 1)->items())->mapWithKeys(fn ($row): array => ["{$row->target->kpi_code}|{$row->target->subject_code}" => $row->state()])->all();

    expect($completeness)->toMatchArray(['required' => 5, 'final' => 1, 'submitted' => 1, 'draft' => 1, 'missing' => 2, 'revisions_open' => 1])
        ->and($completeness['units'])->toBe([['code' => 'PROD-WOVEN', 'name' => 'Woven', 'required' => 5, 'final' => 1]])
        ->and($states)->toMatchArray([
            'TG-QUALITY|W-001' => 'draft',
            'TG-QUALITY|W-002' => 'draft',
            'TG-OUTPUT|W-001' => 'submitted',
            'TG-OUTPUT|W-002' => 'missing',
        ])
        ->and($query->matrix($this->period, $this->all, null, 'missing', '', 1)->total())->toBe(2)
        ->and($query->matrix($this->period, $this->all, 'PROD-WOVEN', null, 'w-002', 1)->total())->toBe(2)
        ->and($query->history($first)->pluck('version_number')->all())->toBe([2, 1]);
});

test('period identity survives: a target period keeps accepting only its own targets', function (): void {
    $other = AssessmentPeriod::query()->findOrFail($this->fixtures->draft(['code' => 'P-2026-12', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31'])->id);
    $target = $this->fixtures->target($this->period, 'TG-QUALITY', 'W-001');

    expect(AccessFixtures::violatedRule(fn () => $this->targets->saveDrafts($this->author, $other->id, $this->all, [$this->fixtures->entry($target, '90')])))->toBe('period_not_accepting_targets');
});

test('the account that wrote a value cannot approve it, even when someone else submitted it', function (): void {
    $target = $this->fixtures->target($this->period, 'TG-QUALITY', 'W-001');

    $this->targets->saveDrafts($this->reviewer, $this->period->id, $this->all, [$this->fixtures->entry($target, '97')]);
    $this->targets->submit($this->author, $this->period->id, $this->all);

    expect(AccessFixtures::violatedRule(fn () => $this->targets->approve($this->reviewer, $this->period->id, $this->all)))->toBe('self_review')
        ->and(AccessFixtures::violatedRule(fn () => $this->targets->approve($this->author, $this->period->id, $this->all)))->toBe('self_review');

    $third = AccessFixtures::userWithRole(Role::HrPerformance, 'BAL');
    $approved = $this->targets->approve(new Actor($third->id, Actor::system('test')->audit), $this->period->id, $this->all);
    $final = ($this->latest)($target);

    expect($approved)->toBe(['approved' => 1, 'skipped' => 0])
        ->and($final->updated_by_user_id)->toBe($this->fixtures->kpi->reviewer->id)
        ->and(fn () => DB::transaction(fn () => DB::table('period_target_versions')->where('id', $final->id)->update(['approved_by_user_id' => $this->fixtures->kpi->author->id])))->toThrow(QueryException::class);
});

test('a draft flagged after the cut-off stays flagged when the deadline moves', function (): void {
    $target = $this->fixtures->target($this->period, 'TG-QUALITY', 'W-001');

    Date::setTestNow(CarbonImmutable::parse('2026-11-06 02:00:00', 'UTC'));
    $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($target, '97')], 'Terlambat');
    app(PeriodService::class)->rescheduleDeadline($this->author, $this->period->id, $this->period->fresh()->lock_version, DeadlineStage::TargetSetting, '2026-11-10', 'Perpanjangan');
    $this->targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($target, '98')]);

    expect(($this->latest)($target)->after_cutoff)->toBeTrue()
        ->and(($this->latest)($target)->change_reason)->toBe('Terlambat');
});
