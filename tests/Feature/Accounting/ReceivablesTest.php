<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\ArImportService;
use App\Modules\Accounting\Application\ArQuery;
use App\Modules\Accounting\Infrastructure\Persistence\Models\ArSnapshot;
use App\Modules\IdentityAccess\Domain\Capability;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\IdentityAccess\Domain\RoleCapabilityMatrix;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AccessFixtures;
use Tests\Support\ReportHarness;

/*
 * Accounts receivable read model: staged snapshots, aging on an as-of date,
 * reconciliation, tax invoice queue, and least-privilege access.
 */

const AR_HEADER = 'record_type,document_number,related_document,customer_code,customer_name,document_date,due_date,currency,amount,status,status_reason,collection_owner,next_action,next_action_date';

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->arLeader = AccessFixtures::userWithRole(Role::TeamLeader, 'ACC-AR');
    $this->accHead = AccessFixtures::userWithRole(Role::DepartmentHead, 'ACC');
    $this->taxLeader = AccessFixtures::userWithRole(Role::TeamLeader, 'ACC-TAX-INVOICE');
    $this->management = AccessFixtures::userWithRole(Role::Management, 'BAL');
    $this->auditor = AccessFixtures::userWithRole(Role::Auditor, 'BAL');
    $this->hr = AccessFixtures::userWithRole(Role::HrPerformance, 'BAL');
    $this->sales = AccessFixtures::userWithRole(Role::TeamLeader, 'COMSUP-SALES');
    $this->imports = app(ArImportService::class);
});

/**
 * @param  list<string>  $lines
 */
function arFile(array $lines): string
{
    $path = storage_path('framework/testing/'.uniqid('ar-', true).'.csv');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, AR_HEADER."\n".implode("\n", $lines)."\n");

    return $path;
}

/**
 * Six invoices around the PRD bucket boundaries on 2026-10-12, a partial
 * payment, a full payment, an over-allocation, a dispute, a split payment,
 * and tax invoice references.
 *
 * @return list<string>
 */
function standardSnapshot(): array
{
    return [
        'invoice,INV-001,,C-01,PT Satu,2026-09-01,2026-10-20,IDR,1000000.00,,,Rina,Telepon ulang,2026-10-14',
        'invoice,INV-002,,C-01,PT Satu,2026-08-01,2026-09-12,IDR,500000.50,,,Rina,,',
        'invoice,INV-003,,C-02,PT Dua,2026-07-01,2026-08-13,IDR,750000.00,disputed,Harga tidak sesuai,Budi,Meeting customer,2026-10-15',
        'invoice,INV-004,,C-02,PT Dua,2026-06-01,2026-07-14,IDR,200000.00,,,Budi,,',
        'invoice,INV-005,,C-03,PT Tiga,2026-05-01,2026-07-13,IDR,300000.00,,,Budi,,',
        'invoice,INV-006,,C-03,PT Tiga,2026-09-15,2026-10-12,IDR,100000.00,,,Rina,,',
        'invoice,INV-007,,C-04,PT Empat,2026-09-01,2026-09-30,IDR,250000.00,,,Rina,,',
        'invoice,INV-008,,C-04,PT Empat,2026-09-01,2026-09-30,IDR,80000.00,,,Rina,,',
        'allocation,PAY-1,INV-002,,,2026-10-01,,IDR,200000.25,,,,,',
        'allocation,PAY-2,INV-007,,,2026-10-02,,IDR,150000.00,,,,,',
        'allocation,PAY-2,INV-008,,,2026-10-02,,IDR,100000.00,,,,,',
        'allocation,PAY-3,INV-007,,,2026-10-05,,IDR,100000.00,,,,,',
        'tax_invoice,010.000-26.00000001,INV-001,,,2026-09-02,,,,approved,,,,',
        'tax_invoice,010.000-26.00000002,INV-003,,,2026-07-02,,,,rejected,NPWP customer tidak valid,,,',
    ];
}

function stage(object $test, User $by, array $lines, string $unit = 'ACC-AR', string $asOf = '2026-10-12', ?string $controlInvoices = null, ?string $controlAllocations = null): ArSnapshot
{
    $path = arFile($lines);

    try {
        return $test->imports->stage(new Actor($by->id, Actor::system('test')->audit), $by, AccessFixtures::unit($unit)->id, 'erp-accounting', $asOf, 'ar.csv', $path, 'csv', $controlInvoices, $controlAllocations);
    } finally {
        @unlink($path);
    }
}

function commitSnapshot(object $test, User $by, ArSnapshot $snapshot): ArSnapshot
{
    return $test->imports->commit(new Actor($by->id, Actor::system('test')->audit), $by, $snapshot->id);
}

test('aging on the as-of date follows the PRD buckets and reconciles with the source totals', function (): void {
    $snapshot = commitSnapshot($this, $this->arLeader, stage($this, $this->arLeader, standardSnapshot(), controlInvoices: '3180000.50', controlAllocations: '550000.25'));
    $summary = ArQuery::summary($snapshot);
    $outstanding = fn (string $bucket): string => $summary['buckets'][$bucket]['outstanding'];

    expect($outstanding('current'))->toBe('1100000.00')            // INV-001 (not due) + INV-006 (due on the as-of date)
        ->and($outstanding('d1_30'))->toBe('300000.25')             // INV-002: 500000.50 − 200000.25, 30 days
        ->and($outstanding('d31_60'))->toBe('750000.00')            // INV-003: 60 days, disputed
        ->and($summary['buckets']['d31_60']['disputed_count'])->toBe(1)
        ->and($outstanding('d61_90'))->toBe('200000.00')            // INV-004: 90 days; INV-005 at 91 days is in the next bucket
        ->and($summary['buckets']['d61_90']['count'])->toBe(1)
        ->and($outstanding('over_90'))->toBe('300000.00')
        ->and($summary['paid'])->toBe(1)                            // INV-007 paid by two payments
        ->and($summary['over_allocated'])->toBe(['count' => 1, 'credit' => '20000.00']) // INV-008: 100000 on 80000
        ->and($summary['outstanding_total'])->toBe('2650000.25')
        ->and($summary['buckets_total'])->toBe('2650000.25')
        ->and($summary['reconciles'])->toBeTrue();
});

test('the aging report lists every invoice with its outstanding, bucket, dispute, and follow-up', function (): void {
    $snapshot = commitSnapshot($this, $this->arLeader, stage($this, $this->arLeader, standardSnapshot()));
    $rows = collect(ReportHarness::rows($this->arLeader, 'ar_aging', ['snapshot' => $snapshot->public_id]))->keyBy('Invoice');

    expect($rows)->toHaveCount(8)
        ->and($rows['INV-002']['Outstanding'])->toBe('300000.25')
        ->and($rows['INV-002']['Hari lewat jatuh tempo'])->toBe('30')
        ->and($rows['INV-003']['Dispute'])->toBe('ya')
        ->and($rows['INV-003']['Bucket'])->toBe('31–60 hari')
        ->and($rows['INV-001']['Tindak lanjut'])->toBe('Telepon ulang')
        ->and($rows['INV-007']['Bucket'])->toBe('Lunas')
        ->and($rows['INV-008']['Bucket'])->toContain('Kelebihan alokasi')
        ->and($rows['INV-005']['Versi bucket'])->toBe('prd-10.2')
        ->and(ReportHarness::rows($this->arLeader, 'ar_aging', ['snapshot' => $snapshot->public_id, 'status' => 'over_90']))->toHaveCount(1)
        ->and(ReportHarness::rows($this->arLeader, 'ar_aging', ['snapshot' => $snapshot->public_id, 'status' => 'disputed']))->toHaveCount(1);

    // The summary report states the reconciliation.
    $summary = collect(ReportHarness::rows($this->arLeader, 'ar_aging_summary', ['snapshot' => $snapshot->public_id]));
    expect($summary->firstWhere('Keterangan', 'Bucket = outstanding')['Nilai'])->toBe('cocok');

    // Tax invoices with the source status and reason, and invoices without one.
    $tax = collect(ReportHarness::rows($this->arLeader, 'tax_invoices', ['snapshot' => $snapshot->public_id]));
    expect($tax->firstWhere('Invoice', 'INV-003')['Alasan status'])->toBe('NPWP customer tidak valid')
        ->and($tax->firstWhere('Invoice', 'INV-001')['Status sumber'])->toBe('approved')
        ->and($tax->where('Keterangan', 'Belum ada referensi faktur pajak'))->toHaveCount(6);
});

test('the as-of date is a business date and the same snapshot ages the same whenever it is read', function (): void {
    // 23:30 Asia/Jakarta on 2026-10-12 is still 2026-10-12 locally, 16:30 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 16:30:00', 'UTC'));
    $snapshot = commitSnapshot($this, $this->arLeader, stage($this, $this->arLeader, standardSnapshot()));
    $first = ArQuery::summary($snapshot);

    // A later date never changes an earlier snapshot.
    $this->travelTo(CarbonImmutable::parse('2026-12-31 01:00:00', 'UTC'));
    expect(ArQuery::summary($snapshot->refresh()))->toBe($first);

    // The next calendar day in Jakarta has begun at 17:00 UTC; a future as-of is refused.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 16:30:00', 'UTC'));
    expect(AccessFixtures::violatedRule(fn () => stage($this, $this->arLeader, ['invoice,INV-9,,C-1,X,2026-10-01,2026-10-30,IDR,1.00,,,,,'], asOf: '2026-10-13')))->toBe('as_of_in_future');
});

test('every problem of a file is reported and nothing of it counts', function (): void {
    $snapshot = stage($this, $this->arLeader, [
        'invoice,INV-1,,C-1,X,2026-10-01,2026-10-30,IDR,100.00,,,,,',
        'invoice,INV-1,,C-1,X,2026-10-01,2026-10-30,IDR,100.00,,,,,',
        'credit_note,CN-1,INV-1,C-1,X,2026-10-05,,IDR,10.00,,,,,',
        'allocation,PAY-1,INV-404,,,2026-10-05,,IDR,10.00,,,,,',
        'allocation,PAY-2,INV-1,,,2026-10-05,,USD,10.00,,,,,',
        'invoice,INV-2,,C-1,X,2026-10-01,2026-09-30,IDR,100.00,,,,,',
        'invoice,INV-3,,C-1,X,2026-10-20,2026-10-30,IDR,100.00,,,,,',
        'invoice,INV-4,,C-1,X,2026-10-01,2026-10-30,IDR,"1,000.00",,,,,',
        'invoice,INV-5,,C-1,X,2026-10-01,2026-10-30,IDR,0.001,,,,,',
        'invoice,INV 6,,C-1,X,2026-10-01,2026-10-30,IDR,1.00,,,,,',
        'tax_invoice,FP-1,INV-1,,,2026-10-02,,,,,,,,',
    ]);

    $errors = implode("\n", $snapshot->errors ?? []);

    expect($snapshot->status)->toBe(ArSnapshot::STAGED)
        ->and($snapshot->error_count)->toBe(10)
        ->and($errors)->toContain('duplikat dari baris 2')
        ->and($errors)->toContain('credit note belum dapat diproses')
        ->and($errors)->toContain('INV-404 tidak ada sebagai invoice')
        ->and($errors)->toContain('mata uang alokasi berbeda')
        ->and($errors)->toContain('due_date sebelum document_date')
        ->and($errors)->toContain('document_date setelah tanggal as-of')
        ->and($errors)->toContain('amount harus angka positif')
        ->and($errors)->toContain('document_number wajib berupa kode tanpa spasi')
        ->and($errors)->toContain('tax_invoice wajib memiliki status')
        ->and($snapshot->documents()->count())->toBe(0)
        ->and(AccessFixtures::violatedRule(fn () => commitSnapshot($this, $this->arLeader, $snapshot)))->toBe('snapshot_has_errors');
});

test('control totals that do not match the source block the commit', function (): void {
    $snapshot = stage($this, $this->arLeader, standardSnapshot(), controlInvoices: '3180000.49');

    expect(AccessFixtures::violatedRule(fn () => commitSnapshot($this, $this->arLeader, $snapshot)))->toBe('control_total_mismatch')
        ->and($snapshot->refresh()->status)->toBe(ArSnapshot::STAGED);
});

test('a file is imported once; a corrected file for the same date is a new version, never an overwrite', function (): void {
    $first = commitSnapshot($this, $this->arLeader, stage($this, $this->arLeader, standardSnapshot()));
    $again = stage($this, $this->arLeader, standardSnapshot());

    expect($again->id)->toBe($first->id)
        ->and(ArSnapshot::query()->count())->toBe(1);

    $corrected = [...standardSnapshot(), 'allocation,PAY-9,INV-001,,,2026-10-10,,IDR,1000000.00,,,,,'];
    $second = commitSnapshot($this, $this->arLeader, stage($this, $this->arLeader, $corrected));

    expect($second->version)->toBe(2)
        ->and($first->refresh()->status)->toBe(ArSnapshot::COMMITTED)
        ->and(ArQuery::summary($first)['buckets']['current']['outstanding'])->toBe('1100000.00')
        ->and(ArQuery::summary($second)['buckets']['current']['outstanding'])->toBe('100000.00');

    // A committed snapshot does not change, also by direct SQL.
    expect(fn () => DB::transaction(fn () => DB::table('ar_documents')->where('ar_snapshot_id', $first->id)->update(['amount' => '1'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('ar_documents')->where('ar_snapshot_id', $first->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('ar_snapshots')->where('id', $first->id)->update(['status' => 'staged'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('ar_documents')->insert([
            ...array_diff_key((array) DB::table('ar_documents')->where('ar_snapshot_id', $first->id)->where('document_type', 'allocation')->first(), ['id' => true]),
            'document_number' => 'PAY-LATE',
        ])))->toThrow(QueryException::class, 'committed receivables snapshot');

    // The audit trail records the import without customer data.
    $audit = json_encode(AuditLog::query()->where('entity_type', 'ar_snapshot')->pluck('after_values')->all());
    expect($audit)->not->toContain('PT Satu')
        ->and($audit)->not->toContain('INV-001');
});

test('financial data is readable only in the accounting scope and kept only by its team', function (): void {
    $snapshot = commitSnapshot($this, $this->arLeader, stage($this, $this->arLeader, standardSnapshot()));
    $filters = ['snapshot' => $snapshot->public_id];

    foreach ([$this->arLeader, $this->accHead, $this->management, $this->auditor] as $reader) {
        expect(ReportHarness::rows($reader, 'ar_aging', $filters))->toHaveCount(8);
    }

    // HR, Sales, another Accounting team, and admins cannot read it.
    expect(fn () => ReportHarness::rows($this->hr, 'ar_aging', $filters))->toThrow(AuthorizationException::class)
        ->and(fn () => ReportHarness::rows($this->sales, 'ar_aging', $filters))->toThrow(AuthorizationException::class)
        ->and(fn () => ReportHarness::rows($this->taxLeader, 'ar_aging', $filters))->toThrow(AuthorizationException::class)
        ->and(fn () => stage($this, $this->taxLeader, standardSnapshot()))->toThrow(AuthorizationException::class)
        // A team leader outside Accounting cannot load receivables for their own team either.
        ->and(fn () => stage($this, $this->sales, standardSnapshot(), 'COMSUP-SALES', '2026-10-09'))->toThrow(AuthorizationException::class)
        ->and(fn () => stage($this, $this->management, standardSnapshot(), asOf: '2026-10-11'))->toThrow(AuthorizationException::class)
        ->and(fn () => stage($this, $this->auditor, standardSnapshot(), asOf: '2026-10-10'))->toThrow(AuthorizationException::class);

    $matrix = new RoleCapabilityMatrix;

    foreach ([Role::HrPerformance, Role::SystemAdmin, Role::Employee] as $role) {
        expect($matrix->allows($role, Capability::AccountingDataView))->toBeFalse($role->value);
    }

    expect(Capability::AccountingDataView->isDelegable())->toBeFalse()
        ->and(Capability::AccountingDataManage->changesKpiValues())->toBeTrue();
});

test('the page offers import only to the team and shows the aging of a committed snapshot', function (): void {
    $snapshot = commitSnapshot($this, $this->arLeader, stage($this, $this->arLeader, standardSnapshot()));

    $this->actingAs($this->hr)->get(route('accounting.index'))->assertForbidden();
    $this->actingAs($this->sales)->get(route('accounting.index'))->assertForbidden();
    $this->actingAs($this->sales)->get(route('dashboard'))->assertDontSee(route('accounting.index'), escape: false);

    Livewire::actingAs($this->management)->test('pages::accounting.index', ['selected' => $snapshot->public_id])
        ->assertDontSee('Impor snapshot')
        ->assertSee('Bucket cocok dengan outstanding')
        ->assertSee('300000.25');

    Livewire::actingAs($this->taxLeader)->test('pages::accounting.index', ['selected' => $snapshot->public_id])
        ->assertDontSee('300000.25')
        ->call('startReject', $snapshot->public_id)
        ->assertNotFound();

    $staged = stage($this, $this->arLeader, ['invoice,INV-X,,C-1,X,2026-10-01,2026-10-30,IDR,1.00,,,,,'], asOf: '2026-10-11');

    Livewire::actingAs($this->arLeader)->test('pages::accounting.index')
        ->assertSee('Impor snapshot')
        ->call('commit', $staged->public_id)
        ->assertHasNoErrors()
        ->call('commit', $staged->public_id)
        ->assertHasErrors('action');

    expect($staged->refresh()->status)->toBe(ArSnapshot::COMMITTED);
});

test('a snapshot holds one currency and at least one invoice', function (): void {
    $mixed = stage($this, $this->arLeader, [
        'invoice,INV-1,,C-1,X,2026-10-01,2026-10-30,IDR,100.00,,,,,',
        'invoice,INV-2,,C-1,X,2026-10-01,2026-10-30,USD,100.00,,,,,',
    ]);
    $empty = stage($this, $this->arLeader, [], asOf: '2026-10-11');

    expect(implode("\n", $mixed->errors ?? []))->toContain('hanya untuk satu mata uang; file ini berisi IDR, USD')
        ->and($empty->error_count)->toBe(1)
        ->and(implode("\n", $empty->errors ?? []))->toContain('tidak berisi invoice')
        ->and(AccessFixtures::violatedRule(fn () => commitSnapshot($this, $this->arLeader, $empty)))->toBe('snapshot_has_errors');
});

test('the same file for another as-of date is refused, and a rejected file may be imported again', function (): void {
    $snapshot = stage($this, $this->arLeader, standardSnapshot());

    expect(AccessFixtures::violatedRule(fn () => stage($this, $this->arLeader, standardSnapshot(), asOf: '2026-10-11')))->toBe('duplicate_file');

    $this->imports->reject(new Actor($this->arLeader->id, Actor::system('test')->audit), $this->arLeader, $snapshot->id, 'Tanggal as-of salah');
    $retry = stage($this, $this->arLeader, standardSnapshot(), asOf: '2026-10-11');

    expect($retry->id)->not->toBe($snapshot->id)
        ->and($retry->status)->toBe(ArSnapshot::STAGED)
        ->and($retry->as_of_date)->toBe('2026-10-11')
        ->and($snapshot->refresh()->status)->toBe(ArSnapshot::REJECTED);
});

test('the page says when the same file was already imported and keeps old staged snapshots listed', function (): void {
    $staged = stage($this, $this->arLeader, standardSnapshot(), asOf: '2026-01-05');

    foreach (range(1, 31) as $day) {
        commitSnapshot($this, $this->arLeader, stage($this, $this->arLeader, ["invoice,INV-{$day},,C-1,X,2026-01-01,2026-10-30,IDR,1.00,,,,,"], asOf: sprintf('2026-%02d-%02d', 2 + intdiv($day, 28), 1 + $day % 28)));
    }

    $path = arFile(standardSnapshot());

    Livewire::actingAs($this->arLeader)->test('pages::accounting.index')
        ->assertSee($staged->public_id)
        ->set('unit', 'ACC-AR')
        ->set('sourceSystem', 'erp-accounting')
        ->set('asOf', '2026-01-05')
        ->set('file', UploadedFile::fake()->createWithContent('ar.csv', (string) file_get_contents($path)))
        ->call('stage')
        ->assertSet('flash', 'File yang sama sudah diimpor sebagai snapshot versi 1 (staged). Tidak ada yang berubah.');

    @unlink($path);
    expect(ArSnapshot::query()->count())->toBe(32);
});
