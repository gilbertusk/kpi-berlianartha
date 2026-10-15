<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Production\Application\PpicBoard;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Domain\PpicStatus;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\AccessFixtures;
use Tests\Support\ProductionHarness as Harness;
use Tests\Support\ReportHarness;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    Harness::useCalendar();
    $this->woven = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    $this->flexo = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-FLEXO');
    $this->warehouse = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WAREHOUSE');
    $this->ppic = AccessFixtures::userWithRole(Role::TeamLeader, 'COMSUP-ADMIN-PPIC');
    $this->management = AccessFixtures::userWithRole(Role::Management, 'BAL');
    $this->sales = AccessFixtures::userWithRole(Role::TeamLeader, 'COMSUP-SALES');
    $this->travelTo(CarbonImmutable::parse(Harness::NOW, 'UTC'));
});

test('the actuals report lists the current version only, in scope, with source references', function (): void {
    Harness::load($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13');
    Harness::load($this->woven, BatchKind::Production, [...Harness::wovenDay(), 'output,EV-8,WO-100,,,M-01,,,2026-10-13 14:00,,pcs,1,,,,,'], 'PROD-WOVEN', '2026-10-13');
    Harness::load($this->flexo, BatchKind::Production, ['output,FX-1,WO-200,,,F-01,,,2026-10-13 08:00,,m,10,,,,,'], 'PROD-FLEXO', '2026-10-13');

    $rows = ReportHarness::rows($this->woven, 'production_actuals');
    $night = collect($rows)->firstWhere('Event', 'EV-3');

    expect($rows)->toHaveCount(8)                                    // version 2 only, Woven only
        ->and(collect($rows)->pluck('Versi batch')->unique()->all())->toBe(['v2'])
        ->and($night)->toMatchArray(['Tanggal' => '2026-10-13', 'Shift' => 'S3', 'Waktu' => '2026-10-14 02:30', 'Kuantitas' => '300.250', 'Diekstrak' => '2026-10-14 09:30'])
        ->and(ReportHarness::rows($this->woven, 'production_actuals', ['status' => 'downtime']))->toHaveCount(2)
        ->and(ReportHarness::rows($this->management, 'production_actuals'))->toHaveCount(9)
        ->and(ReportHarness::rows($this->management, 'production_actuals', ['unit' => 'PROD-FLEXO']))->toHaveCount(1)
        ->and(fn () => ReportHarness::rows($this->woven, 'production_actuals', ['unit' => 'PROD-FLEXO']))->toThrow(AuthorizationException::class)
        ->and(fn () => ReportHarness::rows($this->sales, 'production_actuals'))->toThrow(AuthorizationException::class);
});

test('the warehouse report keeps the location categories apart', function (): void {
    Harness::load($this->warehouse, BatchKind::Warehouse, ['RM-A1,raw_material,YARN-1,L-1,kg,100.5', 'QR-1,reject_quarantine,P-1,,pcs,30', 'SC-1,scrap,YARN-1,,kg,2'], 'PROD-WAREHOUSE', '2026-10-13');

    expect(ReportHarness::rows($this->warehouse, 'warehouse_stock', ['status' => 'reject_quarantine']))->toBe([[
        'Team' => 'PROD-WAREHOUSE', 'Tanggal as-of' => '2026-10-13', 'Kategori lokasi' => 'Reject/Quarantine', 'Lokasi' => 'QR-1', 'Item' => 'P-1', 'Lot' => null,
        'Satuan' => 'pcs', 'Kuantitas' => '30.000', 'Sumber' => 'mes-fixture', 'Versi batch' => 'v1', 'Diekstrak' => '2026-10-14 09:30',
    ]])->and(ReportHarness::rows($this->warehouse, 'warehouse_stock'))->toHaveCount(3);
});

test('the PPIC report follows the board and its status filter', function (): void {
    Harness::load($this->ppic, BatchKind::WorkOrders, [
        'WO-100,SO-1,C-1,P-1,WEAVE,M-01,3000,pcs,2026-10-10,2026-10-15,2026-10-16,ready,approved,normal',
        'WO-101,SO-1,C-1,P-2,WEAVE,M-02,500,pcs,,,2026-10-13,partial,,emergency',
    ], 'COMSUP-ADMIN-PPIC', '2026-10-14');
    app(PpicBoard::class)->move(Harness::actor($this->ppic), $this->ppic, 'WO-100', null, PpicStatus::InProduction);

    expect(collect(ReportHarness::rows($this->woven, 'ppic_work_orders'))->pluck('Overdue', 'Work order')->all())->toBe(['WO-101' => 'ya', 'WO-100' => 'tidak'])
        ->and(collect(ReportHarness::rows($this->ppic, 'ppic_work_orders', ['status' => 'in_production']))->pluck('Work order')->all())->toBe(['WO-100'])
        ->and(collect(ReportHarness::rows($this->ppic, 'ppic_work_orders', ['status' => 'unset']))->pluck('Work order')->all())->toBe(['WO-101'])
        ->and(fn () => ReportHarness::rows($this->sales, 'ppic_work_orders'))->toThrow(AuthorizationException::class);
});
