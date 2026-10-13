<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Application\AccessControl;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\KpiGovernance\Application\KpiDefinitionService;
use App\Modules\KpiGovernance\Application\KpiTemplateService;
use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplateItem;
use App\Modules\Organization\Application\EmployeeService;
use App\Modules\PeriodAssignment\Application\PeriodQuery;
use App\Modules\PeriodAssignment\Application\PeriodService;
use App\Modules\PeriodAssignment\Domain\ApprovalStage;
use App\Modules\PeriodAssignment\Domain\Coverage;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\AssessmentPeriod;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignment;
use App\Modules\PeriodAssignment\Infrastructure\Persistence\Models\PeriodAssignmentApprover;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;
use Tests\Support\PeriodFixtures;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->fixtures = new PeriodFixtures(new KpiFixtures);
    $this->actor = $this->fixtures->kpi->authorActor();

    $this->assignment = fn (AssessmentPeriod $period, string $subjectCode): PeriodAssignment => PeriodAssignment::query()
        ->where('assessment_period_id', $period->id)
        ->where('subject_code', $subjectCode)
        ->firstOrFail();

    $this->approverNames = fn (PeriodAssignment $assignment, ApprovalStage $stage): array => PeriodAssignmentApprover::query()
        ->where('period_assignment_id', $assignment->id)
        ->where('stage', $stage->value)
        ->orderBy('user_name')
        ->pluck('user_name')
        ->all();
});

test('the snapshot records exactly who is assessed, where, under which template, and by whom', function (): void {
    $leader = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    $head = AccessFixtures::userWithRole(Role::DepartmentHead, 'PROD');
    $management = AccessFixtures::userWithRole(Role::Management, 'BAL');
    AccessFixtures::userWithRole(Role::TeamLeader, 'ACC-AR');
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-100');
    AccessFixtures::employee(null, 'ACC-AR', number: 'AR-100');

    $kpi = $this->fixtures->kpi('SN-OUTPUT');
    $template = $this->fixtures->publishedTemplate('T-SN-WOVEN', 'PROD-WOVEN', [$kpi->id => '100']);

    $period = $this->fixtures->opened();
    $woven = ($this->assignment)($period, 'W-100');
    $accounting = ($this->assignment)($period, 'AR-100');

    expect($woven->coverage)->toBe(Coverage::Covered)
        ->and($woven->kpi_template_version_id)->toBe($template->id)
        ->and($woven->template_code)->toBe('T-SN-WOVEN')
        ->and($woven->unit_code)->toBe('PROD-WOVEN')
        ->and(array_column($woven->unit_path, 'code'))->toBe(['BAL', 'PROD', 'PROD-WOVEN'])
        ->and(($this->approverNames)($woven, ApprovalStage::Verification))->toBe([$leader->name])
        ->and(($this->approverNames)($woven, ApprovalStage::Review))->toBe([$head->name])
        ->and(($this->approverNames)($woven, ApprovalStage::FinalApproval))->toBe([$management->name])
        // Both HR fixture accounts are company-scoped.
        ->and(($this->approverNames)($woven, ApprovalStage::Calibration))->toHaveCount(2)
        ->and($woven->approver_gaps)->toBeNull();

    // No template for Accounting: visible, not assessed, and the missing
    // Manager is reported instead of being replaced by an assumption.
    expect($accounting->coverage)->toBe(Coverage::NoTemplate)
        ->and($accounting->approver_gaps)->toBe(['review' => 'not_configured'])
        ->and(($this->approverNames)($accounting, ApprovalStage::Review))->toBe([]);
});

test('an employee is never their own approver', function (): void {
    $leader = AccessFixtures::user('Leader Woven');
    AccessFixtures::employee($leader, 'PROD-WOVEN', number: 'W-TL');
    AccessFixtures::grant($leader, Role::TeamLeader, 'PROD-WOVEN');
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-200');

    $kpi = $this->fixtures->kpi('SN-SELF');
    $this->fixtures->publishedTemplate('T-SN-SELF', 'PROD-WOVEN', [$kpi->id => '100']);
    $period = $this->fixtures->opened();

    $own = ($this->assignment)($period, 'W-TL');
    $member = ($this->assignment)($period, 'W-200');

    expect(($this->approverNames)($own, ApprovalStage::Verification))->toBe([])
        ->and($own->approver_gaps['verification'])->toBe(ApprovalStage::GAP_SELF_ONLY)
        ->and(($this->approverNames)($member, ApprovalStage::Verification))->toBe([$leader->name]);
});

test('a transfer after the snapshot does not change the old period and a later period follows the new team', function (): void {
    $employee = AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-300');
    $kpi = $this->fixtures->kpi('SN-MOVE');
    $this->fixtures->publishedTemplate('T-SN-MOVE-WOVEN', 'PROD-WOVEN', [$kpi->id => '100']);
    $this->fixtures->publishedTemplate('T-SN-MOVE-QC', 'PROD-QC', [$kpi->id => '100']);

    $november = $this->fixtures->opened();
    $before = ($this->assignment)($november, 'W-300')->getAttributes();

    app(EmployeeService::class)->transferHome($this->actor, $employee->id, $employee->lock_version, AccessFixtures::unit('PROD-QC')->id, '2026-11-10', 'Inspector', 'Rotasi');
    app(EmployeeService::class)->rename($this->actor, $employee->id, $employee->fresh()->lock_version, 'Nama Baru', 'Koreksi ejaan');

    $december = $this->fixtures->opened(['code' => 'P-2026-12', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31']);

    expect(($this->assignment)($november, 'W-300')->getAttributes())->toBe($before)
        ->and(($this->assignment)($november, 'W-300')->unit_code)->toBe('PROD-WOVEN')
        ->and(($this->assignment)($december, 'W-300')->unit_code)->toBe('PROD-QC')
        ->and(($this->assignment)($december, 'W-300')->position_title)->toBe('Inspector')
        ->and(($this->assignment)($december, 'W-300')->subject_name)->toBe('Nama Baru')
        ->and(($this->assignment)($december, 'W-300')->template_code)->toBe('T-SN-MOVE-QC');
});

test('snapshot rows cannot be changed or deleted, even through direct SQL', function (): void {
    AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-400');
    $kpi = $this->fixtures->kpi('SN-IMMUTABLE');
    $this->fixtures->publishedTemplate('T-SN-IMMUTABLE', 'PROD-WOVEN', [$kpi->id => '100']);
    $period = $this->fixtures->opened();
    $assignment = ($this->assignment)($period, 'W-400');

    expect(fn () => DB::transaction(fn () => DB::table('period_assignments')->where('id', $assignment->id)->update(['unit_code' => 'PROD-QC'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('period_assignment_approvers')->where('period_assignment_id', $assignment->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('period_assignment_approvers')->where('period_assignment_id', $assignment->id)->update(['user_name' => 'Orang lain'])))->toThrow(QueryException::class)
        ->and($assignment->fresh()?->unit_code)->toBe('PROD-WOVEN');
});

test('each period uses the template version in force on its reference date', function (): void {
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-500');
    $output = $this->fixtures->kpi('SN-V-OUTPUT');
    $waste = $this->fixtures->kpi('SN-V-WASTE');
    $v1 = $this->fixtures->publishedTemplate('T-SN-VERSIONS', 'PROD-WOVEN', [$output->id => '70', $waste->id => '30']);

    $templates = app(KpiTemplateService::class);
    $v2 = $templates->startNewVersion($this->actor, $v1->kpi_template_id, 'Bobot waste dinaikkan');
    $items = KpiTemplateItem::query()->where('kpi_template_version_id', $v2->id)->orderBy('sort_order')->get();
    $templates->updateItem($this->actor, $items[0]->id, $v2->fresh()->lock_version, '60', null, true, null);
    $templates->updateItem($this->actor, $items[1]->id, $v2->fresh()->lock_version, '40', null, true, null);
    $templates->updateDraftHeader($this->actor, $v2->id, $v2->fresh()->lock_version, null, 'TEST-SIGNOFF-T2', '2026-12-01');
    $v2 = $this->fixtures->kpi->publishTemplate($v2->fresh());

    $november = $this->fixtures->opened();
    $december = $this->fixtures->opened(['code' => 'P-2026-12', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31']);

    expect(($this->assignment)($november, 'W-500')->kpi_template_version_id)->toBe($v1->id)
        ->and(($this->assignment)($november, 'W-500')->template_version_number)->toBe(1)
        ->and(($this->assignment)($december, 'W-500')->kpi_template_version_id)->toBe($v2->id)
        ->and(($this->assignment)($december, 'W-500')->template_version_number)->toBe(2);
});

test('a position template wins over the unit template, and unit scorecards use team templates', function (): void {
    $operator = AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-600');
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-601');
    app(EmployeeService::class)->transferHome($this->actor, $operator->id, $operator->lock_version, AccessFixtures::unit('PROD-WOVEN')->id, '2026-10-20', 'Operator', 'Penetapan jabatan');

    $kpi = $this->fixtures->kpi('SN-POSITION');
    $teamKpi = $this->fixtures->kpi('SN-TEAM', 'PROD-WOVEN', ['evaluation_subject' => 'team']);
    $this->fixtures->publishedTemplate('T-SN-GENERIC', 'PROD-WOVEN', [$kpi->id => '100']);
    $this->fixtures->publishedTemplate('T-SN-OPERATOR', 'PROD-WOVEN', [$kpi->id => '100'], 'employee', 'Operator');
    $this->fixtures->publishedTemplate('T-SN-TEAMCARD', 'PROD-WOVEN', [$teamKpi->id => '100'], 'team');

    $period = $this->fixtures->opened(['code' => 'P-PROD-11', 'unit' => 'PROD']);

    expect(($this->assignment)($period, 'W-600')->template_code)->toBe('T-SN-OPERATOR')
        ->and(($this->assignment)($period, 'W-601')->template_code)->toBe('T-SN-GENERIC')
        ->and(($this->assignment)($period, 'PROD-WOVEN')->template_code)->toBe('T-SN-TEAMCARD')
        ->and(($this->assignment)($period, 'PROD-QC')->coverage)->toBe(Coverage::NoTemplate)
        // The period is scoped to Produksi: nothing outside it is snapshotted.
        ->and(PeriodAssignment::query()->where('assessment_period_id', $period->id)->where('unit_code', 'like', 'ACC%')->exists())->toBeFalse();
});

test('a template that pins a KPI version no longer in force does not cover its subjects', function (): void {
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-700');
    $kpi = $this->fixtures->kpi('SN-RETIRED');
    $this->fixtures->publishedTemplate('T-SN-RETIRED', 'PROD-WOVEN', [$kpi->id => '100']);
    app(KpiDefinitionService::class)->retire($this->fixtures->kpi->reviewerActor(), $kpi->id, $kpi->fresh()->lock_version, '2026-10-25', 'KPI dihentikan');

    $other = $this->fixtures->kpi('SN-OTHER', 'PROD-QC');
    AccessFixtures::employee(null, 'PROD-QC', number: 'Q-700');
    $this->fixtures->publishedTemplate('T-SN-QC', 'PROD-QC', [$other->id => '100']);

    $period = $this->fixtures->opened();

    expect(($this->assignment)($period, 'W-700')->coverage)->toBe(Coverage::KpiNotInForce)
        ->and(($this->assignment)($period, 'Q-700')->coverage)->toBe(Coverage::Covered);
});

test('the preview of a draft period equals the snapshot stored on opening', function (): void {
    AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-800');
    AccessFixtures::employee(null, 'ACC-AR', number: 'AR-800');
    $kpi = $this->fixtures->kpi('SN-PREVIEW');
    $this->fixtures->publishedTemplate('T-SN-PREVIEW', 'PROD-WOVEN', [$kpi->id => '100']);

    $query = app(PeriodQuery::class);
    $draft = $this->fixtures->draft();
    $read = fn (AssessmentPeriod $period): array => $query->assignments($period, PeriodFixtures::everything(), 'employee', null, false, '', 1, CarbonImmutable::now());

    $preview = $read($draft);
    $opened = app(PeriodService::class)->open($this->actor, $draft->id, $draft->lock_version);
    $stored = $read($opened);
    $describe = fn (array $result): array => collect($result['rows']->items())
        ->map(fn ($view): array => [$view->subjectCode, $view->unitLabel, $view->templateLabel, $view->coverage->value, $view->approverText(ApprovalStage::Verification)])
        ->sort()
        ->values()
        ->all();

    expect($preview['preview'])->toBeTrue()
        ->and($stored['preview'])->toBeFalse()
        ->and($describe($preview))->toBe($describe($stored))
        ->and($preview['summary'])->toBe($stored['summary'])
        ->and($stored['summary']['employees'])->toBe(PeriodAssignment::query()->where('assessment_period_id', $opened->id)->where('subject_type', 'employee')->count());
});

test('readers only see assignments inside their own unit scope', function (): void {
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-900');
    AccessFixtures::employee(null, 'ACC-AR', number: 'AR-900');
    $kpi = $this->fixtures->kpi('SN-SCOPE');
    $this->fixtures->publishedTemplate('T-SN-SCOPE', 'PROD-WOVEN', [$kpi->id => '100']);
    $period = $this->fixtures->opened();

    $leader = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    $scope = app(AccessControl::class)->unitScope($leader, Capability::PeriodView);
    $codes = collect(app(PeriodQuery::class)->assignments($period, $scope, null, null, false, '', 1, CarbonImmutable::now())['rows']->items())->pluck('subjectCode')->all();

    expect($codes)->toContain('W-900', 'PROD-WOVEN')
        ->and($codes)->not->toContain('AR-900', 'ACC-AR', 'BAL');
});

test('an approver whose role ended before the period is opened is not recorded, and the successor is', function (): void {
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-950');
    $kpi = $this->fixtures->kpi('SN-ROLE');
    $this->fixtures->publishedTemplate('T-SN-ROLE', 'PROD-WOVEN', [$kpi->id => '100']);

    $former = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN', until: '2026-11-15');
    $successor = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN', from: '2026-11-15');
    $draft = $this->fixtures->draft();

    // Opened on 20 November: the reference date (1 November) is already past.
    $period = AccessFixtures::on('2026-11-20', fn () => app(PeriodService::class)->open($this->actor, $draft->id, $draft->lock_version));

    expect(($this->approverNames)(($this->assignment)($period, 'W-950'), ApprovalStage::Verification))->toBe([$successor->name])
        ->and($former->name)->not->toBe($successor->name);
});
