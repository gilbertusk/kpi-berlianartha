<?php

declare(strict_types=1);

use App\Modules\ActualEvidence\Application\ActualAccess;
use App\Modules\ActualEvidence\Application\ActualEntry;
use App\Modules\ActualEvidence\Application\ActualImportService;
use App\Modules\ActualEvidence\Application\ActualService;
use App\Modules\ActualEvidence\Application\ImportSheet;
use App\Modules\ActualEvidence\Domain\ActualStatus;
use App\Modules\ActualEvidence\Domain\EntryKind;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\ActualImportBatch;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\ActualImportRow;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\Shared\Domain\Exceptions\ConcurrencyConflict;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;
use Tests\Support\PeriodFixtures;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->fixtures = new PeriodFixtures(new KpiFixtures);
    $this->imports = app(ActualImportService::class);
    $this->author = $this->fixtures->kpi->authorActor();
    $this->reviewer = $this->fixtures->kpi->reviewerActor();
    $this->all = ActualAccess::everything();

    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-001');
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-002');

    $quality = $this->fixtures->kpi('AI-QUALITY', 'PROD-WOVEN', ['decimal_places' => 2]);
    $daily = $this->fixtures->kpi('AI-OUTPUT', 'PROD-WOVEN', ['unit' => 'pieces', 'value_type' => 'integer', 'frequency' => 'daily']);
    $this->fixtures->publishedTemplate('T-AI-WOVEN', 'PROD-WOVEN', [$quality->id => '60', $daily->id => '40']);
    $this->period = $this->fixtures->inProgress();

    $this->paths = [];
    $this->header = 'kpi_code,subject_type,subject_code,window_start,window_end,unit,actual_value,source_system,source_reference,note';

    $this->file = function (array $lines, string $name = 'aktual.csv', ?string $header = null): string {
        $path = tempnam(sys_get_temp_dir(), 'kpi-import-');
        file_put_contents($path, implode("\n", [$header ?? $this->header, ...$lines])."\n");
        $this->paths[] = $path;

        return $path;
    };

    $this->stage = fn (array $lines, string $name = 'aktual.csv', $actor = null): ActualImportBatch => $this->imports->stage($actor ?? $this->author, $this->period, $this->all, $name, ($this->file)($lines));

    $this->rowsOf = fn (ActualImportBatch $batch): array => ActualImportRow::query()->where('actual_import_batch_id', $batch->id)->orderBy('row_number')->get()
        ->mapWithKeys(fn (ActualImportRow $row): array => [$row->row_number => [$row->status, $row->errors ?? []]])
        ->all();
});

afterEach(function (): void {
    foreach ($this->paths as $path) {
        @unlink($path);
    }
});

test('a valid file is previewed, committed atomically with reconciliation totals, and a retry adds nothing', function (): void {
    $batch = ($this->stage)([
        'AI-QUALITY,employee,W-001,2026-11-01,2026-11-30,percent,96.5,,,Rekap QC',
        'AI-QUALITY,employee,W-002,2026-11-01,2026-11-30,percent,0,,,',
        'AI-OUTPUT,employee,W-001,2026-11-02,2026-11-02,pieces,1200,MES,WO-1001,',
        'AI-OUTPUT,employee,W-001,2026-11-03,2026-11-03,pieces,N/A,,,Mesin berhenti terjadwal',
        'AI-OUTPUT,employee,W-002,2026-11-02,2026-11-02,pieces,,,,',
    ]);

    expect($batch->status)->toBe(ActualImportBatch::STATUS_STAGED)
        ->and($batch->file_format)->toBe('csv')
        ->and($batch->summary)->toMatchArray(['rows' => 5, 'valid' => 4, 'skipped' => 1, 'error' => 0, 'not_applicable' => 1])
        ->and(KpiActual::query()->count())->toBe(0);

    $committed = $this->imports->commit($this->author, $batch->id, $batch->lock_version, $this->all, null);
    $versions = KpiActualVersion::query()->orderBy('id')->get();

    expect($committed->status)->toBe(ActualImportBatch::STATUS_COMMITTED)
        ->and($versions)->toHaveCount(4)
        ->and($versions->every(fn (KpiActualVersion $version): bool => $version->status === ActualStatus::Draft && $version->source === 'import' && $version->actual_import_batch_id === $batch->id))->toBeTrue()
        ->and($versions->pluck('actual_value')->all())->toBe(['96.5000', '0.0000', '1200.0000', null])
        ->and($versions[3]->entry_kind)->toBe(EntryKind::NotApplicable)
        ->and(KpiActual::query()->where('source_reference', 'WO-1001')->value('source_system'))->toBe('MES')
        ->and($committed->summary['reconciliation'])->toMatchArray([
            'file_rows' => 5,
            'rows_applied' => 4,
            'actuals_written' => 4,
            'file_value_total' => '1296.5000',
            'written_value_total' => '1296.5000',
            'created' => 4,
        ])
        ->and(OutboxMessage::query()->where('event_type', 'ImportCommitted')->where('aggregate_id', $batch->public_id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'actual_import.committed')->where('entity_id', $batch->public_id)->value('after_values'))->toMatchArray(['rows_applied' => 4]);

    $this->imports->commit($this->author, $batch->id, $batch->lock_version, $this->all, null);

    expect(KpiActualVersion::query()->count())->toBe(4)
        ->and(OutboxMessage::query()->where('event_type', 'ImportCommitted')->count())->toBe(1)
        ->and(AccessFixtures::violatedRule(fn () => ($this->stage)([
            'AI-QUALITY,employee,W-001,2026-11-01,2026-11-30,percent,96.5,,,Rekap QC',
            'AI-QUALITY,employee,W-002,2026-11-01,2026-11-30,percent,0,,,',
            'AI-OUTPUT,employee,W-001,2026-11-02,2026-11-02,pieces,1200,MES,WO-1001,',
            'AI-OUTPUT,employee,W-001,2026-11-03,2026-11-03,pieces,N/A,,,Mesin berhenti terjadwal',
            'AI-OUTPUT,employee,W-002,2026-11-02,2026-11-02,pieces,,,,',
        ])))->toBe('import_already_committed');
});

test('invalid rows are reported per row, can be downloaded, and block the whole file', function (): void {
    $batch = ($this->stage)([
        'AI-QUALITY,employee,W-001,2026-11-01,2026-11-30,percent,96.555,,,',
        'AI-QUALITY,employee,W-002,2026-11-01,2026-11-30,pieces,96,,,',
        'AI-OUTPUT,employee,W-001,2026-11-01,2026-11-02,pieces,10,,,',
        'AI-OUTPUT,employee,W-001,2026-10-31,2026-10-31,pieces,10,,,',
        'AI-OUTPUT,employee,W-002,2026-11-02,2026-11-02,pieces,10,MES,WO-7,',
        'AI-OUTPUT,employee,W-002,2026-11-03,2026-11-03,pieces,11,MES,WO-7,',
        'AI-OUTPUT,employee,W-002,2026-11-04,2026-11-04,pieces,N/A,,,',
        'AI-OUTPUT,employee,W-002,2026-11-05,2026-11-05,pieces,12,MES,,',
        'AI-NONE,employee,W-001,2026-11-05,2026-11-05,pieces,12,,,',
        'AI-OUTPUT,employee,W-001,2026-11-06,2026-11-06,pieces,=1+1,,,',
        'AI-OUTPUT,employee,W-001,2026-11-07,2026-11-07,pieces,15,,,',
    ]);

    $rows = ($this->rowsOf)($batch);

    expect($batch->error_count)->toBe(10)
        ->and($batch->valid_count)->toBe(1)
        ->and($rows[2][1])->toBe(['KPI ini menerima paling banyak 2 angka di belakang koma.'])
        ->and($rows[3][1])->toBe(['Satuan aktual (pieces) tidak sesuai dengan satuan KPI (percent).'])
        ->and($rows[4][1][0])->toContain('KPI harian diisi per tanggal')
        ->and($rows[5][1][0])->toContain('harus berada di dalam periode')
        ->and($rows[6][1])->toBe(['Duplikat: record sumber yang sama muncul lagi pada baris 7.'])
        ->and($rows[7][1])->toBe(['Duplikat: record sumber yang sama sudah ada pada baris 6.'])
        ->and($rows[8][1])->toBe(['Aktual N/A wajib disertai kolom note berisi alasan.'])
        ->and($rows[9][1])->toBe(['source_system dan source_reference harus diisi bersamaan.'])
        ->and($rows[10][1])->toBe(['KPI untuk subjek ini tidak ditemukan pada periode ini dalam scope Anda.'])
        ->and($rows[11][1][0])->toContain('Nilai aktual harus berupa angka')
        ->and($rows[12][0])->toBe('valid')
        ->and(AccessFixtures::violatedRule(fn () => $this->imports->commit($this->author, $batch->id, $batch->lock_version, $this->all, null)))->toBe('import_has_errors')
        ->and(KpiActual::query()->count())->toBe(0);

    $errors = $this->imports->errorRows($batch);

    expect($errors)->toHaveCount(11)
        ->and($errors[0][0])->toBe('row_number')
        ->and(end($errors[0]))->toBe('errors')
        // The formula typed into the value cell is neutralized in the download.
        ->and(collect($errors)->firstWhere(0, '11')[9])->toBe("'=1+1");
});

test('two rows for the same window are duplicates', function (): void {
    $batch = ($this->stage)([
        'AI-QUALITY,employee,W-001,2026-11-01,2026-11-30,percent,96,,,',
        'AI-QUALITY,employee,W-001,2026-11-01,2026-11-30,percent,97,,,',
    ]);

    expect(($this->rowsOf)($batch)[2][1][0])->toContain('muncul lagi pada baris 3')
        ->and(($this->rowsOf)($batch)[3][1][0])->toContain('sudah ada pada baris 2');
});

test('a commit is rolled back completely when an actual changed after staging', function (): void {
    $batch = ($this->stage)([
        'AI-QUALITY,employee,W-001,2026-11-01,2026-11-30,percent,96,,,',
        'AI-QUALITY,employee,W-002,2026-11-01,2026-11-30,percent,95,,,',
    ]);

    $target = $this->fixtures->target($this->period, 'AI-QUALITY', 'W-002');
    app(ActualService::class)->saveDrafts($this->reviewer, $this->period->id, $this->all, [new ActualEntry('x', $target->id, '2026-11-01', '2026-11-30', '90', 'percent')]);

    expect(AccessFixtures::violatedRule(fn () => $this->imports->commit($this->author, $batch->id, $batch->lock_version, $this->all, null)))->toBe('import_stale')
        ->and(KpiActualVersion::query()->where('source', 'import')->count())->toBe(0)
        ->and(KpiActualVersion::query()->count())->toBe(1)
        ->and($batch->fresh()?->status)->toBe(ActualImportBatch::STATUS_STAGED)
        ->and(OutboxMessage::query()->where('event_type', 'ImportCommitted')->count())->toBe(0);
});

test('a source record imported once is a duplicate in a later file', function (): void {
    $first = ($this->stage)(['AI-OUTPUT,employee,W-001,2026-11-02,2026-11-02,pieces,1200,MES,WO-1001,'], 'hari-1.csv');
    $this->imports->commit($this->author, $first->id, $first->lock_version, $this->all, null);

    // The same source record for another date is a duplicate; the same row
    // sent again in a new file changes nothing.
    $second = ($this->stage)(['AI-OUTPUT,employee,W-001,2026-11-03,2026-11-03,pieces,1200,MES,WO-1001,'], 'hari-2.csv');
    $again = ($this->stage)(['AI-OUTPUT,employee,W-001,2026-11-02,2026-11-02,pieces,1200,MES,WO-1001,', 'AI-QUALITY,employee,W-001,2026-11-01,2026-11-30,percent,,,,'], 'ulang.csv');

    expect(($this->rowsOf)($second)[2][1][0])->toContain('Record sumber ini sudah diimpor sebagai aktual lain (2026-11-02)')
        ->and(($this->rowsOf)($again)[2][0])->toBe('unchanged')
        ->and(AccessFixtures::violatedRule(fn () => $this->imports->commit($this->author, $again->id, $again->lock_version, $this->all, null)))->toBe('import_nothing_to_commit')
        ->and(KpiActual::query()->count())->toBe(1);
});

test('an XLSX workbook is read like a CSV, including typed number and date cells', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'kpi-import-').'.xlsx';
    $this->paths[] = $path;

    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(explode(',', $this->header)));
    $writer->addRow(Row::fromValues(['AI-QUALITY', 'employee', 'W-001', '2026-11-01', '2026-11-30', 'percent', 96.5, '', '', 'Dari Excel']));
    $date = (new Style)->withFormat('yyyy-mm-dd');
    $writer->addRow(Row::fromValuesWithStyles(
        ['AI-OUTPUT', 'employee', 'W-001', new DateTimeImmutable('2026-11-02'), new DateTimeImmutable('2026-11-02'), 'pieces', 1200, '', '', ''],
        [3 => $date, 4 => $date],
    ));
    $writer->addRow(Row::fromValues(['AI-QUALITY', 'employee', 'W-002', '2026-11-01', '2026-11-30', 'percent', 96.555, '', '', '']));
    $writer->close();

    $batch = $this->imports->stage($this->author, $this->period, $this->all, 'aktual.xlsx', $path);
    $rows = ActualImportRow::query()->where('actual_import_batch_id', $batch->id)->orderBy('row_number')->get();

    expect($batch->file_format)->toBe('xlsx')
        ->and($rows->pluck('status')->all())->toBe(['valid', 'valid', 'error'])
        ->and($rows[0]->actual_value)->toBe('96.5000')
        ->and($rows[1]->actual_value)->toBe('1200.0000')
        ->and($rows[1]->window_start)->toBe('2026-11-02')
        ->and($rows[2]->errors)->toBe(['KPI ini menerima paling banyak 2 angka di belakang koma.']);
});

test('files that are not the expected sheet are refused before staging', function (string $name, string $contents, string $rule): void {
    $path = tempnam(sys_get_temp_dir(), 'kpi-import-');
    file_put_contents($path, $contents);
    $this->paths[] = $path;

    expect(AccessFixtures::violatedRule(fn () => $this->imports->stage($this->author, $this->period, $this->all, $name, $path)))->toBe($rule)
        ->and(ActualImportBatch::query()->count())->toBe(0);
})->with([
    'missing column' => ['a.csv', "kpi_code,subject_type,subject_code,unit,actual_value\nAI-QUALITY,employee,W-001,percent,1\n", 'import_invalid_file'],
    'not a workbook' => ['a.xlsx', 'this is not a zip archive', 'import_invalid_file'],
    'wrong extension' => ['a.xls', 'x', 'import_invalid_file'],
    'not UTF-8' => ['a.csv', "kpi_code,subject_type,subject_code,window_start,window_end,unit,actual_value\nAI-QUALITY,employee,W-001,2026-11-01,2026-11-30,percent,\xE9\n", 'import_invalid_file'],
    'header only' => ['a.csv', "kpi_code,subject_type,subject_code,window_start,window_end,unit,actual_value\n", 'import_empty'],
    'too many rows' => ['a.csv', "kpi_code,subject_type,subject_code,window_start,window_end,unit,actual_value\n".str_repeat("a\n", ActualImportService::MAX_ROWS + 1), 'import_invalid_file'],
    'too large' => ['a.csv', str_repeat('x', ActualImportService::MAX_BYTES + 1), 'import_too_large'],
]);

test('a stale or discarded batch cannot be committed and staging is per account', function (): void {
    $lines = ['AI-QUALITY,employee,W-001,2026-11-01,2026-11-30,percent,96,,,'];
    $mine = ($this->stage)($lines);
    $theirs = ($this->stage)($lines, 'aktual.csv', $this->reviewer);

    expect($mine->fresh()?->status)->toBe(ActualImportBatch::STATUS_STAGED)
        ->and($theirs->status)->toBe(ActualImportBatch::STATUS_STAGED)
        ->and(fn () => $this->imports->commit($this->author, $mine->id, $mine->lock_version + 3, $this->all, null))->toThrow(ConcurrencyConflict::class)
        ->and(KpiActual::query()->count())->toBe(0);

    $discarded = $this->imports->discard($this->author, $mine->id, $mine->lock_version);

    expect(AccessFixtures::violatedRule(fn () => $this->imports->commit($this->author, $mine->id, $discarded->lock_version, $this->all, null)))->toBe('import_not_staged');
});

test('a workbook that expands far beyond its upload size is refused', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'kpi-import-').'.xlsx';
    $this->paths[] = $path;
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString('[Content_Types].xml', '<x/>');
    $archive->addFromString('xl/worksheets/sheet1.xml', str_repeat('0', 21 * 1024 * 1024));
    $archive->close();

    expect(filesize($path))->toBeLessThan(ActualImportService::MAX_BYTES)
        ->and(AccessFixtures::violatedRule(fn () => $this->imports->stage($this->author, $this->period, $this->all, 'bom.xlsx', $path)))->toBe('import_invalid_file')
        ->and(ActualImportBatch::query()->count())->toBe(0);
});

test('numeric spreadsheet cells keep the digits the sheet shows and large totals do not overflow', function (): void {
    $text = new ReflectionMethod(ImportSheet::class, 'floatText');
    $total = new ReflectionMethod(ActualImportService::class, 'total');

    expect($text->invoke(null, 1234567.8912))->toBe('1234567.8912')
        ->and($text->invoke(null, 96.5))->toBe('96.5')
        ->and($text->invoke(null, 0.1 + 0.2))->toBe('0.3')
        ->and($text->invoke(null, 96.555))->toBe('96.555')
        ->and($text->invoke(null, 1200.0))->toBe('1200')
        ->and($total->invoke(null, array_fill(0, 5000, '999999999999.9999')))->toBe('4999999999999999.5000')
        ->and($total->invoke(null, ['10.5000', '-0.7500', null, '-20.0000']))->toBe('-10.2500');
});
