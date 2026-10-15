<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Production\Application\ProductionAccess;
use App\Modules\Production\Application\ProductionImportService;
use App\Modules\Production\Application\ProductionQuery;
use App\Modules\Production\Domain\BatchKind;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionBatch;
use App\Modules\Production\Infrastructure\Persistence\Models\ProductionRecord;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\ProductionHarness as Harness;

/*
 * Production actuals, packing, deliveries, and warehouse stock as staged
 * batches: shifts and operational dates from the (fixture) shift calendar,
 * exact totals per unit, late corrections as versions, and team scope.
 */

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    Harness::useCalendar();
    $this->woven = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');
    $this->flexo = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-FLEXO');
    $this->prodHead = AccessFixtures::userWithRole(Role::DepartmentHead, 'PROD');
    $this->warehouse = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WAREHOUSE');
    $this->packing = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-PACKING-LOGISTIC');
    $this->logistic = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-LOGISTIC');
    $this->management = AccessFixtures::userWithRole(Role::Management, 'BAL');
    $this->auditor = AccessFixtures::userWithRole(Role::Auditor, 'BAL');
    $this->hr = AccessFixtures::userWithRole(Role::HrPerformance, 'BAL');
    $this->sales = AccessFixtures::userWithRole(Role::TeamLeader, 'COMSUP-SALES');
    $this->arLeader = AccessFixtures::userWithRole(Role::TeamLeader, 'ACC-AR');
    $this->travelTo(CarbonImmutable::parse(Harness::NOW, 'UTC'));
});

test('shift and operational date come from the calendar, also for a night shift past midnight', function (): void {
    $batch = Harness::load($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13');
    $record = fn (string $event): ProductionRecord => ProductionRecord::query()->where('production_batch_id', $batch->id)->where('event_key', $event)->firstOrFail();

    expect($batch->status)->toBe(ProductionBatch::COMMITTED)
        ->and($batch->calendar_version)->toBe('fixture-3x8')
        ->and([$record('EV-1')->shift_code, $record('EV-1')->operational_date])->toBe(['S1', '2026-10-13'])
        ->and([$record('EV-2')->shift_code, $record('EV-2')->operational_date])->toBe(['S2', '2026-10-13'])
        ->and([$record('EV-3')->shift_code, $record('EV-3')->operational_date])->toBe(['S3', '2026-10-13'])   // 02:30 on the 14th
        ->and([$record('EV-6')->shift_code, $record('EV-6')->operational_date])->toBe(['S3', '2026-10-13'])   // 05:30 on the 14th
        ->and($record('EV-3')->started_at?->toIso8601String())->toBe('2026-10-13T19:30:00+00:00')       // stored in UTC
        ->and($record('EV-5')->downtime_minutes)->toBe(45)
        ->and($record('EV-1')->detail('operator_code'))->toBe('OP-7');
});

test('totals reconcile per unit and are never converted between units', function (): void {
    $batch = Harness::load($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13');
    $totals = ProductionQuery::totals($batch);
    $breakdown = ProductionQuery::breakdown($batch);

    expect($totals['quantity'])->toBe(['pcs' => '2100.750', 'm' => '150.125'])
        ->and($totals['reject'])->toBe(['pcs' => '30.000', 'm' => '0.000'])
        ->and($totals['rework'])->toBe(['pcs' => '5.000', 'm' => '2.500'])
        ->and($totals['waste'])->toBe(['kg' => '3.500'])
        ->and($totals['downtime_minutes'])->toBe(105)
        ->and($totals['rows'])->toBe(['downtime' => 2, 'output' => 4, 'waste' => 1])
        ->and($batch->missing_reason_count)->toBe(2)   // EV-3 reject without code, EV-6 downtime without reason
        ->and($breakdown['reconciles'])->toBeTrue()
        ->and(collect($breakdown['rows'])->firstWhere(fn (array $row): bool => $row['shift'] === 'S3' && $row['unit'] === 'pcs'))->toMatchArray(['machine' => 'M-02', 'good' => '300.250', 'reject' => '10.000']);

    // One control number cannot be checked against two units.
    $mixed = Harness::stage($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13', controls: ['quantity' => '2250.875'], source: 'mes-other');

    expect(AccessFixtures::violatedRule(fn () => Harness::commit($this->woven, $mixed)))->toBe('control_total_units');
});

test('exact decimals: 0.1 plus 0.2 is 0.3 and more than three decimals is refused', function (): void {
    $batch = Harness::load($this->woven, BatchKind::Production, [
        'output,EV-1,WO-1,,,M-01,,,2026-10-13 08:00,,m,0.1,,,,,',
        'output,EV-2,WO-1,,,M-01,,,2026-10-13 09:00,,m,0.2,,,,,',
        'output,EV-3,WO-1,,,M-01,,,2026-10-13 10:00,,m,12345678901.999,,,,,',
    ], 'PROD-WOVEN', '2026-10-13', ['quantity' => '12345678902.299']);
    $refused = Harness::stage($this->woven, BatchKind::Production, ['output,EV-9,WO-1,,,M-01,,,2026-10-13 08:00,,m,0.1234,,,,,'], 'PROD-WOVEN', '2026-10-13');

    expect(ProductionQuery::totals($batch)['quantity'])->toBe(['m' => '12345678902.299'])
        ->and(implode("\n", $refused->errors ?? []))->toContain('maks. 3 desimal');
});

test('a row of another operational date is refused; it belongs in the batch of its own date', function (): void {
    $batch = Harness::stage($this->woven, BatchKind::Production, [
        'output,EV-1,WO-1,,,M-01,,,2026-10-13 06:30,,pcs,1,,,,,',   // night shift of the 12th
        'output,EV-2,WO-1,,,M-01,,,2026-10-13 07:30,,pcs,1,,,,,',   // day shift of the 13th
    ], 'PROD-WOVEN', '2026-10-12');

    expect($batch->error_count)->toBe(1)
        ->and($batch->errors[0] ?? '')->toBe('Baris 3: termasuk tanggal operasional 2026-10-13 (shift S1); impor pada batch tanggal tersebut.');
});

test('a declared shift that the calendar contradicts is refused at the boundary minute', function (): void {
    $batch = Harness::stage($this->woven, BatchKind::Production, [
        'output,EV-1,WO-1,,,M-01,,S1,2026-10-13 15:00,,pcs,1,,,,,',
        'output,EV-2,WO-1,,,M-01,,S1,2026-10-13 14:59,,pcs,1,,,,,',
    ], 'PROD-WOVEN', '2026-10-13');

    expect($batch->error_count)->toBe(1)
        ->and($batch->errors[0] ?? '')->toContain('shift_code S1 tidak sesuai kalender; 2026-10-13 15:00 termasuk shift S2');
});

test('with an end-of-shift calendar the night shift counts on the next date', function (): void {
    Harness::useCalendar('end');
    $line = 'output,EV-1,WO-1,,,M-01,,,2026-10-13 23:30,,pcs,1,,,,,';

    expect(Harness::stage($this->woven, BatchKind::Production, [$line], 'PROD-WOVEN', '2026-10-13')->error_count)->toBe(1)
        ->and(Harness::stage($this->woven, BatchKind::Production, [$line], 'PROD-WOVEN', '2026-10-14', source: 'mes-other')->error_count)->toBe(0);
});

test('without an approved shift calendar shift-based data cannot be imported, other kinds can', function (): void {
    config(['production.shift_calendars' => []]);

    expect(AccessFixtures::violatedRule(fn () => Harness::stage($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13')))->toBe('shift_calendar_missing')
        ->and(AccessFixtures::violatedRule(fn () => Harness::stage($this->packing, BatchKind::Packing, ['PK-1,WO-1,,2026-10-13 08:00,,pcs,10,,'], 'PROD-PACKING-LOGISTIC', '2026-10-13')))->toBe('shift_calendar_missing')
        ->and(Harness::stage($this->warehouse, BatchKind::Warehouse, ['RM-A1,raw_material,YARN-1,L-1,kg,100'], 'PROD-WAREHOUSE', '2026-10-13')->error_count)->toBe(0);
});

test('a duplicate event is refused in the file and across the current batches of other dates', function (): void {
    $inFile = Harness::stage($this->woven, BatchKind::Production, [
        'output,EV-1,WO-1,,,M-01,,,2026-10-13 08:00,,pcs,1,,,,,',
        'output,EV-1,WO-1,,,M-01,,,2026-10-13 09:00,,pcs,1,,,,,',
    ], 'PROD-WOVEN', '2026-10-13');

    Harness::load($this->woven, BatchKind::Production, ['output,EV-7,WO-1,,,M-01,,,2026-10-12 08:00,,pcs,1,,,,,'], 'PROD-WOVEN', '2026-10-12');
    $again = Harness::stage($this->woven, BatchKind::Production, ['output,EV-7,WO-1,,,M-01,,,2026-10-13 08:00,,pcs,1,,,,,'], 'PROD-WOVEN', '2026-10-13');

    // Another team's event with the same code is not a duplicate.
    $flexo = Harness::stage($this->flexo, BatchKind::Production, ['output,EV-7,WO-1,,,F-01,,,2026-10-13 08:00,,pcs,1,,,,,'], 'PROD-FLEXO', '2026-10-13');

    expect($inFile->errors[0] ?? '')->toBe('Baris 3: duplikat dari baris 2.')
        ->and($again->errors[0] ?? '')->toBe('Baris 2: event EV-7 sudah dihitung pada batch tanggal 2026-10-12.')
        ->and($flexo->error_count)->toBe(0);
});

test('negative, malformed, and impossible quantities and times are refused', function (): void {
    $batch = Harness::stage($this->woven, BatchKind::Production, [
        'output,EV-1,WO-1,,,M-01,,,2026-10-13 08:00,,pcs,-5,,,,,',
        'output,EV-2,WO-1,,,M-01,,,2026-10-13 08:00,,pcs,"1,5",,,,,',
        'output,EV-3,WO-1,,,M-01,,,2026-10-13 08:00,,pcs,0,0,0,,,',
        'downtime,EV-4,,,,M-01,,,2026-10-13 10:00,2026-10-13 09:00,,,,,,,RC',
        'downtime,EV-5,,,,M-01,,,2026-10-13 08:00,2026-10-14 09:00,,,,,,,RC',
        'output,EV-6,WO-1,,,M-01,,,2026-10-14 11:00,,pcs,1,,,,,',
        'output,EV-7,WO-1,,,M-01,,,2026-10-13 8:00,,pcs,1,,,,,',
        'scrap,EV-8,WO-1,,,M-01,,,2026-10-13 08:00,,pcs,1,,,,,',
        'output,EV-9,,,,M-01,,,2026-10-13 08:00,,pcs,1,,,,,',
        'waste,EV-10,,,,M-01,,,2026-10-13 08:00,,kg,,,,0,,RW',
        'output,EV-11,WO-1,,,M-01,,,2026-10-13 08:00,,PCS!,1,,,,,',
    ], 'PROD-WOVEN', '2026-10-13');
    $errors = implode("\n", $batch->errors ?? []);

    expect($batch->error_count)->toBe(11)
        ->and($errors)->toContain('good_qty tidak boleh negatif')
        ->and($errors)->toContain('good_qty harus angka dengan titik desimal')
        ->and($errors)->toContain('output membutuhkan good_qty, reject_qty, atau rework_qty lebih dari 0')
        ->and($errors)->toContain('ended_at harus setelah started_at')
        ->and($errors)->toContain('downtime lebih dari 24 jam')
        ->and($errors)->toContain('started_at di masa depan')
        ->and($errors)->toContain('started_at harus YYYY-MM-DD HH:MM')
        ->and($errors)->toContain('record_type harus output, downtime, atau waste')
        ->and($errors)->toContain('work_order wajib diisi')
        ->and($errors)->toContain('waste_qty harus lebih dari 0')
        ->and($errors)->toContain('unit wajib berupa kode satuan')
        ->and($batch->records()->count())->toBe(0)
        ->and(AccessFixtures::violatedRule(fn () => Harness::commit($this->woven, $batch)))->toBe('batch_has_errors');
});

test('a late correction is the next version; the final version stays and nothing is overwritten', function (): void {
    $first = Harness::load($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13');
    $late = [...Harness::wovenDay(), 'output,EV-8,WO-100,P-1,WEAVE,M-01,,,2026-10-13 14:00,,pcs,99.25,,,,,'];
    $second = Harness::stage($this->woven, BatchKind::Production, $late, 'PROD-WOVEN', '2026-10-13');

    // Staged is not current yet.
    expect(ProductionBatch::query()->current()->pluck('id')->all())->toBe([$first->id]);

    Harness::commit($this->woven, $second);
    $audit = AuditLog::query()->where('action', 'production_batch.committed')->where('entity_id', $second->public_id)->firstOrFail();

    expect($second->version)->toBe(2)
        ->and(ProductionBatch::query()->current()->pluck('id')->all())->toBe([$second->id])
        ->and(ProductionQuery::totals($first->refresh())['quantity']['pcs'])->toBe('2100.750')
        ->and(ProductionQuery::totals($second)['quantity']['pcs'])->toBe('2200.000')
        ->and($audit->after_values['supersedes'] ?? null)->toBe($first->public_id)
        ->and(json_encode($audit->after_values))->not->toContain('OP-7');

    // A final batch does not change, also by direct SQL.
    expect(fn () => DB::transaction(fn () => DB::table('production_records')->where('production_batch_id', $first->id)->update(['quantity' => '1'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('production_records')->where('production_batch_id', $first->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('production_batches')->where('id', $first->id)->update(['status' => 'staged'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('production_records')->insert([
            ...array_diff_key((array) DB::table('production_records')->where('production_batch_id', $first->id)->first(), ['id' => true]),
            'event_key' => 'EV-LATE',
        ])))->toThrow(QueryException::class, 'final production batch');

    // Nor is a staged row moved into a final batch.
    $staged = Harness::stage($this->woven, BatchKind::Production, ['output,EV-99,WO-1,,,M-01,,,2026-10-12 08:00,,pcs,1,,,,,'], 'PROD-WOVEN', '2026-10-12');

    expect(fn () => DB::transaction(fn () => DB::table('production_records')->where('production_batch_id', $staged->id)->update(['production_batch_id' => $first->id])))->toThrow(QueryException::class, 'final production batch');
});

test('the same file is the same batch; a superseded or rejected content comes back as a new version', function (): void {
    $batch = Harness::stage($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13');

    expect(Harness::stage($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13')->id)->toBe($batch->id);

    app(ProductionImportService::class)->reject(Harness::actor($this->woven), $this->woven, $batch->id, 'Ekstraksi salah');
    $first = Harness::commit($this->woven, Harness::stage($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13'));
    $second = Harness::load($this->woven, BatchKind::Production, [...Harness::wovenDay(), 'output,EV-8,WO-100,,,M-01,,,2026-10-13 14:00,,pcs,1,,,,,'], 'PROD-WOVEN', '2026-10-13');

    // Going back to the first content is a correction too: a new current version, not the old one.
    $back = Harness::stage($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13');

    expect([$first->version, $second->version, $back->version])->toBe([2, 3, 4])
        ->and($back->status)->toBe(ProductionBatch::STAGED)
        // The same production file for another date fails on the operational date of every row.
        ->and(Harness::stage($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-12')->error_count)->toBe(7);

    // An unchanged stock snapshot is valid again on the next day.
    $stock = ['RM-A1,raw_material,YARN-1,L-1,kg,100'];
    Harness::load($this->warehouse, BatchKind::Warehouse, $stock, 'PROD-WAREHOUSE', '2026-10-13');

    expect(Harness::load($this->warehouse, BatchKind::Warehouse, $stock, 'PROD-WAREHOUSE', '2026-10-14')->coverage_date)->toBe('2026-10-14');
});

test('two staged batches that share an event cannot both be committed, nor an older version after a newer one', function (): void {
    $monday = Harness::stage($this->woven, BatchKind::Production, ['output,EV-1,WO-1,,,M-01,,,2026-10-12 08:00,,pcs,1,,,,,'], 'PROD-WOVEN', '2026-10-12');
    $tuesday = Harness::stage($this->woven, BatchKind::Production, ['output,EV-1,WO-1,,,M-01,,,2026-10-13 08:00,,pcs,1,,,,,'], 'PROD-WOVEN', '2026-10-13');
    Harness::commit($this->woven, $monday);

    expect(AccessFixtures::violatedRule(fn () => Harness::commit($this->woven, $tuesday)))->toBe('event_counted_elsewhere')
        ->and($tuesday->refresh()->status)->toBe(ProductionBatch::STAGED);

    $older = Harness::stage($this->woven, BatchKind::Production, ['output,EV-2,WO-1,,,M-01,,,2026-10-13 09:00,,pcs,1,,,,,'], 'PROD-WOVEN', '2026-10-13');
    $newer = Harness::stage($this->woven, BatchKind::Production, ['output,EV-3,WO-1,,,M-01,,,2026-10-13 10:00,,pcs,1,,,,,'], 'PROD-WOVEN', '2026-10-13');
    Harness::commit($this->woven, $newer);

    expect(AccessFixtures::violatedRule(fn () => Harness::commit($this->woven, $older)))->toBe('newer_version_committed');
});

test('an invalid shift calendar configuration stops shift-based imports with a clear message', function (): void {
    config(['production.shift_calendars' => [[...Harness::calendar(), 'overnight_operational_date' => 'midnight']]]);

    expect(AccessFixtures::violatedRule(fn () => Harness::stage($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13')))->toBe('shift_calendar_invalid')
        ->and(Harness::stage($this->warehouse, BatchKind::Warehouse, ['RM-A1,raw_material,YARN-1,,kg,1'], 'PROD-WAREHOUSE', '2026-10-13')->error_count)->toBe(0);

    $this->actingAs($this->woven)->get(route('production.index'))->assertOk()->assertSee('Konfigurasi kalender shift tidak valid');
});

test('control totals must match the source before a batch counts', function (): void {
    $wrong = Harness::stage($this->woven, BatchKind::Production, ['output,EV-1,WO-1,,,M-01,,,2026-10-13 08:00,,pcs,10,2,1,,DF,'], 'PROD-WOVEN', '2026-10-13', controls: ['quantity' => '10', 'reject' => '3']);

    expect(AccessFixtures::violatedRule(fn () => Harness::commit($this->woven, $wrong)))->toBe('control_total_mismatch')
        ->and(AccessFixtures::violatedRule(fn () => Harness::stage($this->warehouse, BatchKind::Warehouse, ['RM-A1,raw_material,YARN-1,,kg,1'], 'PROD-WAREHOUSE', '2026-10-13', controls: ['reject' => '1'])))->toBe('invalid_control_total')
        ->and(AccessFixtures::violatedRule(fn () => Harness::stage($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13', extractedAt: '2026-10-12 23:00')))->toBe('invalid_extracted_at')
        ->and(AccessFixtures::violatedRule(fn () => Harness::stage($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-15')))->toBe('coverage_in_future');
});

test('packing, delivery, and warehouse keep their own responsibilities and rules', function (): void {
    $packing = Harness::load($this->packing, BatchKind::Packing, ['PK-1,WO-100,P-1,2026-10-13 09:00,S1,pcs,500,5,'], 'PROD-PACKING-LOGISTIC', '2026-10-13');
    $delivery = Harness::stage($this->logistic, BatchKind::Delivery, [
        'DO-1,WO-100,C-1,2026-10-13,pcs,500,2026-10-14,POD-1',
        'DO-2,WO-101,C-1,2026-10-13,pcs,10,2026-10-12,',
        'DO-3,WO-102,C-2,2026-10-13,pcs,10,,POD-3',
        'DO-4,WO-103,C-2,2026-10-12,pcs,10,,',
        'DO-5,WO-104,C-2,2026-10-13,pcs,10,2026-10-15,',
    ], 'PROD-LOGISTIC', '2026-10-13');
    $stock = Harness::stage($this->warehouse, BatchKind::Warehouse, [
        'RM-A1,raw_material,YARN-1,L-1,kg,100.5',
        'WIP-1,work_in_process,P-1,,pcs,0',
        'FG-1,finished_goods,P-1,,pcs,1200',
        'QR-1,reject_quarantine,P-1,,pcs,30',
        'RT-1,return,P-1,,pcs,4',
        'SC-1,scrap,YARN-1,,kg,2',
        'X-1,transit,P-1,,pcs,1',
        'FG-2,finished_goods,P-2,,pcs,-1',
    ], 'PROD-WAREHOUSE', '2026-10-13');
    $deliveryErrors = implode("\n", $delivery->errors ?? []);

    expect(ProductionQuery::totals($packing)['quantity'])->toBe(['pcs' => '500.000'])
        ->and(ProductionQuery::totals($packing)['rework'])->toBe(['pcs' => '5.000'])
        ->and($packing->missing_reason_count)->toBe(1)
        ->and($delivery->error_count)->toBe(4)
        ->and($deliveryErrors)->toContain('delivered_date sebelum shipped_date')
        ->and($deliveryErrors)->toContain('pod_reference membutuhkan delivered_date')
        ->and($deliveryErrors)->toContain('bukan tanggal kirim batch ini')
        ->and($deliveryErrors)->toContain('delivered_date di masa depan')
        ->and($stock->error_count)->toBe(2)
        ->and(implode("\n", $stock->errors ?? []))->toContain('location_category harus salah satu dari: raw_material, work_in_process, finished_goods, reject_quarantine, return, scrap')
        ->and(implode("\n", $stock->errors ?? []))->toContain('quantity tidak boleh negatif');

    // Each team loads only its own kind of data.
    expect(fn () => Harness::stage($this->woven, BatchKind::Packing, ['PK-9,WO-1,,2026-10-13 09:00,,pcs,1,,'], 'PROD-WOVEN', '2026-10-13'))->toThrow(AuthorizationException::class)
        ->and(fn () => Harness::stage($this->packing, BatchKind::Delivery, ['DO-9,WO-1,C-1,2026-10-13,pcs,1,,'], 'PROD-PACKING-LOGISTIC', '2026-10-13'))->toThrow(AuthorizationException::class)
        ->and(fn () => Harness::stage($this->logistic, BatchKind::Packing, ['PK-9,WO-1,,2026-10-13 09:00,,pcs,1,,'], 'PROD-LOGISTIC', '2026-10-13'))->toThrow(AuthorizationException::class)
        ->and(fn () => Harness::stage($this->warehouse, BatchKind::Production, Harness::wovenDay(), 'PROD-WAREHOUSE', '2026-10-13'))->toThrow(AuthorizationException::class);
});

test('only the team, its department head, and readers in scope see a batch', function (): void {
    $batch = Harness::load($this->woven, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13');
    $access = app(ProductionAccess::class);

    expect($access->canRead($this->woven, $batch))->toBeTrue()
        ->and($access->canRead($this->prodHead, $batch))->toBeTrue()
        ->and($access->canRead($this->management, $batch))->toBeTrue()
        ->and($access->canRead($this->auditor, $batch))->toBeTrue()
        ->and($access->canRead($this->hr, $batch))->toBeTrue()
        ->and($access->canRead($this->flexo, $batch))->toBeFalse()
        ->and($access->canRead($this->sales, $batch))->toBeFalse()
        ->and($access->canRead($this->arLeader, $batch))->toBeFalse()
        // The department head loads for the team; readers and other teams do not.
        ->and(Harness::stage($this->prodHead, BatchKind::Production, ['output,EV-90,WO-1,,,M-01,,,2026-10-12 08:00,,pcs,1,,,,,'], 'PROD-WOVEN', '2026-10-12')->error_count)->toBe(0)
        ->and(fn () => Harness::stage($this->flexo, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13'))->toThrow(AuthorizationException::class)
        ->and(fn () => Harness::stage($this->management, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13'))->toThrow(AuthorizationException::class)
        ->and(fn () => Harness::stage($this->auditor, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13'))->toThrow(AuthorizationException::class)
        ->and(fn () => Harness::stage($this->hr, BatchKind::Production, Harness::wovenDay(), 'PROD-WOVEN', '2026-10-13'))->toThrow(AuthorizationException::class)
        ->and(fn () => Harness::stage($this->sales, BatchKind::Production, Harness::wovenDay(), 'COMSUP-SALES', '2026-10-13'))->toThrow(AuthorizationException::class)
        ->and(fn () => Harness::commit($this->flexo, Harness::stage($this->woven, BatchKind::Production, ['output,EV-2,WO-1,,,M-01,,,2026-10-12 09:00,,pcs,1,,,,,'], 'PROD-WOVEN', '2026-10-12', source: 'mes-other')))->toThrow(AuthorizationException::class);
});

test('a day of output at volume is staged, committed, and totalled exactly', function (): void {
    $rows = [];
    $expected = 0;

    foreach (range(1, ProductionImportService::MAX_ROWS) as $i) {
        $minute = $i % 480;
        $rows[] = sprintf('output,EV-%d,WO-%d,,,M-%02d,,,2026-10-13 %02d:%02d,,pcs,%d.125,1,,,DF,', $i, $i % 50, $i % 12, 7 + intdiv($minute, 60), $minute % 60, $i);
        $expected += $i;
    }

    $started = microtime(true);
    $batch = Harness::load($this->woven, BatchKind::Production, $rows, 'PROD-WOVEN', '2026-10-13');
    $totals = ProductionQuery::totals($batch);
    $seconds = microtime(true) - $started;
    fwrite(STDERR, sprintf("\n[production volume] rows=%d seconds=%.2f peak_memory_mb=%.1f\n", count($rows), $seconds, memory_get_peak_usage(true) / 1048576));

    expect($totals['quantity'])->toBe(['pcs' => sprintf('%d.000', $expected + 2500)])   // 20000 × 0.125 = 2500
        ->and($totals['reject'])->toBe(['pcs' => '20000.000'])
        ->and(ProductionQuery::breakdown($batch)['reconciles'])->toBeTrue();
});
