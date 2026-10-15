<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Production\Application\PpicBoard;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Domain\PpicStatus;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\ProductionHarness as Harness;

/*
 * The PPIC board: work orders and schedule from the source, the board's own
 * append-only status history, flags, filters, and who may move work orders.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    Harness::useCalendar();
    $this->ppic = AccessFixtures::userWithRole(Role::TeamLeader, 'COMSUP-ADMIN-PPIC');
    $this->woven = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    $this->management = AccessFixtures::userWithRole(Role::Management, 'BAL');
    $this->sales = AccessFixtures::userWithRole(Role::TeamLeader, 'COMSUP-SALES');
    $this->travelTo(CarbonImmutable::parse(Harness::NOW, 'UTC'));
    $this->board = app(PpicBoard::class);

    // work_order,order_number,customer_code,product_code,process_code,machine_code,planned_qty,unit,planned_start,planned_end,need_date,material_status,design_status,priority
    Harness::load($this->ppic, BatchKind::WorkOrders, [
        'WO-100,SO-1,C-1,P-1,WEAVE,M-01,3000,pcs,2026-10-10,2026-10-15,2026-10-16,ready,approved,normal',
        'WO-101,SO-1,C-1,P-2,WEAVE,M-02,500,pcs,2026-10-08,2026-10-12,2026-10-13,partial,approved,emergency',
        'WO-102,SO-2,C-2,P-3,WEAVE,M-02,200,m,2026-10-12,2026-10-20,2026-10-21,not_ready,pending,',
    ], 'COMSUP-ADMIN-PPIC', '2026-10-13');
    $this->workOrders = Harness::load($this->ppic, BatchKind::WorkOrders, [
        'WO-100,SO-1,C-1,P-1,WEAVE,M-01,3000,pcs,2026-10-10,2026-10-15,2026-10-17,ready,approved,normal',
        'WO-101,SO-1,C-1,P-2,WEAVE,M-02,500,pcs,2026-10-08,2026-10-12,2026-10-13,partial,approved,emergency',
        'WO-102,SO-2,C-2,P-3,WEAVE,M-02,200,m,2026-10-12,2026-10-20,2026-10-21,not_ready,pending,',
        'WO-103,SO-3,C-3,P-4,FLEXO,F-01,100,pcs,,,2026-10-30,ready,not_required,',
    ], 'COMSUP-ADMIN-PPIC', '2026-10-14');
    Harness::load($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13');
});

/**
 * @param  array<string, string>  $filters
 * @return array<string, array<string, mixed>>
 */
function boardRows(object $test, array $filters = [], ?object $user = null): array
{
    return collect($test->board->rows($user ?? $test->ppic, $filters))->keyBy(fn (array $row): string => (string) $row['record']->work_order)->all();
}

test('the board shows the latest source schedule with overdue, material, emergency, schedule-change flags, and progress', function (): void {
    $rows = boardRows($this);

    expect(array_keys($rows))->toBe(['WO-101', 'WO-100', 'WO-102', 'WO-103'])   // by need date
        ->and($rows['WO-101'])->toMatchArray(['overdue' => true, 'emergency' => true, 'material_not_ready' => true, 'schedule_changed' => false, 'status' => null])
        ->and($rows['WO-100'])->toMatchArray(['overdue' => false, 'material_not_ready' => false, 'schedule_changed' => true, 'output_by_team' => ['PROD-WOVEN' => '1800.500']])
        ->and($rows['WO-100']['previous_schedule'])->toContain('kebutuhan 2026-10-16')
        ->and($rows['WO-102'])->toMatchArray(['material_not_ready' => true, 'output_by_team' => ['PROD-WOVEN' => '150.125']])
        ->and($rows['WO-103'])->toMatchArray(['schedule_changed' => false, 'output_by_team' => []])
        ->and($this->board->currentBatch()?->id)->toBe($this->workOrders->id)
        ->and($this->board->currentBatch()?->source_extracted_at->toIso8601String())->toBe('2026-10-14T02:30:00+00:00');
});

test('every move keeps time, actor, work order, and the reason for a late or backward move', function (): void {
    $actor = Harness::actor($this->ppic);
    $move = fn (string $wo, ?PpicStatus $from, PpicStatus $to, ?string $reason = null) => $this->board->move($actor, $this->ppic, $wo, $from, $to, $reason);

    $move('WO-100', null, PpicStatus::WaitingMaterial);
    $move('WO-100', PpicStatus::WaitingMaterial, PpicStatus::InProduction);

    expect(AccessFixtures::violatedRule(fn () => $move('WO-101', null, PpicStatus::InProduction)))->toBe('late_reason_required')
        ->and(AccessFixtures::violatedRule(fn () => $move('WO-100', PpicStatus::InProduction, PpicStatus::WaitingMaterial)))->toBe('backward_reason_required')
        ->and(AccessFixtures::violatedRule(fn () => $move('WO-100', null, PpicStatus::Qc)))->toBe('stale_status')
        ->and(AccessFixtures::violatedRule(fn () => $move('WO-100', PpicStatus::InProduction, PpicStatus::InProduction)))->toBe('same_status')
        ->and(AccessFixtures::violatedRule(fn () => $move('WO-999', null, PpicStatus::Qc)))->toBe('unknown_work_order');

    $late = $move('WO-101', null, PpicStatus::InProduction, 'Benang terlambat dari supplier');
    $move('WO-101', PpicStatus::InProduction, PpicStatus::Completed, 'Selesai terlambat dua hari');
    $move('WO-100', PpicStatus::InProduction, PpicStatus::WaitingMaterial, 'Material habis di tengah produksi');
    $rows = boardRows($this);
    $history = $this->board->history($this->ppic, 'WO-100');

    expect($late->was_overdue)->toBeTrue()
        ->and($late->need_date)->toBe('2026-10-13')
        ->and($late->changed_by_user_id)->toBe($this->ppic->id)
        ->and($late->production_batch_id)->toBe($this->workOrders->id)
        ->and($rows['WO-101'])->toMatchArray(['status' => PpicStatus::Completed, 'overdue' => false])
        ->and(array_map(fn ($change) => [$change->sequence, $change->from_status?->value, $change->to_status->value], $history))->toBe([
            [1, null, 'WAITING_MATERIAL'],
            [2, 'WAITING_MATERIAL', 'IN_PRODUCTION'],
            [3, 'IN_PRODUCTION', 'WAITING_MATERIAL'],
        ])
        ->and(AuditLog::query()->where('action', 'ppic_status.changed')->where('entity_id', 'WO-101')->count())->toBe(2);

    // The history is append-only.
    expect(fn () => DB::transaction(fn () => DB::table('ppic_status_changes')->where('work_order', 'WO-100')->update(['to_status' => 'COMPLETED'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('ppic_status_changes')->where('work_order', 'WO-100')->delete()))->toThrow(QueryException::class);
});

test('the board filters by customer, order, work order, need date, machine, and status', function (): void {
    $this->board->move(Harness::actor($this->ppic), $this->ppic, 'WO-102', null, PpicStatus::WaitingMaterial);
    $keys = fn (array $filters): array => array_keys(boardRows($this, $filters));

    expect($keys(['customer' => 'C-1']))->toBe(['WO-101', 'WO-100'])
        ->and($keys(['order' => 'SO-2']))->toBe(['WO-102'])
        ->and($keys(['work_order' => 'WO-103']))->toBe(['WO-103'])
        ->and($keys(['need_from' => '2026-10-17', 'need_to' => '2026-10-21']))->toBe(['WO-100', 'WO-102'])
        ->and($keys(['machine' => 'M-02']))->toBe(['WO-101', 'WO-102'])
        ->and($keys(['status' => 'WAITING_MATERIAL']))->toBe(['WO-102'])
        ->and($keys(['status' => PpicBoard::UNSET]))->toBe(['WO-101', 'WO-100', 'WO-103']);
});

test('production teams read the board; only the PPIC team moves work orders; other teams see nothing', function (): void {
    expect(count($this->board->rows($this->woven)))->toBe(4)
        ->and(count($this->board->rows($this->management)))->toBe(4)
        ->and(fn () => $this->board->rows($this->sales))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->board->move(Harness::actor($this->woven), $this->woven, 'WO-100', null, PpicStatus::Qc))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->board->move(Harness::actor($this->management), $this->management, 'WO-100', null, PpicStatus::Qc))->toThrow(AuthorizationException::class)
        // The schedule comes only from the PPIC team's source data.
        ->and(fn () => Harness::stage($this->woven, BatchKind::WorkOrders, ['WO-1,,,,,,1,pcs,,,2026-10-30,ready,,'], 'PROD-WOVEN', '2026-10-14'))->toThrow(AuthorizationException::class);
});

test('progress keeps teams apart: pieces of one work order at two processes are never added', function (): void {
    $hotCut = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-HOT-CUT', '2026-10-14');
    Harness::load($hotCut, BatchKind::Production, [
        'output,HC-1,WO-100,P-1,CUT,H-01,,,2026-10-13 09:00,,pcs,1700,,,,,',
        'output,HC-2,WO-100,P-1,CUT,H-01,,,2026-10-13 10:00,,kg,12.5,,,,,',
    ], 'PROD-HOT-CUT', '2026-10-13');

    expect(boardRows($this)['WO-100'])->toMatchArray([
        'output_by_team' => ['PROD-HOT-CUT' => '1700.000', 'PROD-WOVEN' => '1800.500'],
        'output_in_other_units' => ['PROD-HOT-CUT kg'],
    ]);
});
