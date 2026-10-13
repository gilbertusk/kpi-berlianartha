<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Exceptions\ConcurrencyConflict;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use App\Modules\Shared\Infrastructure\Persistence\Models\OutboxMessage;
use App\Modules\Target\Application\TargetCsv;
use App\Modules\Target\Application\TargetImportService;
use App\Modules\Target\Application\TargetService;
use App\Modules\Target\Domain\TargetStatus;
use App\Modules\Target\Infrastructure\Persistence\Models\PeriodTargetVersion;
use App\Modules\Target\Infrastructure\Persistence\Models\TargetImportBatch;
use App\Modules\Target\Infrastructure\Persistence\Models\TargetImportRow;
use Tests\Support\AccessFixtures;
use Tests\Support\KpiFixtures;
use Tests\Support\PeriodFixtures;

beforeEach(function (): void {
    $this->travelTo(AccessFixtures::now());
    AccessFixtures::seedStructure();
    $this->fixtures = new PeriodFixtures(new KpiFixtures);
    $this->imports = app(TargetImportService::class);
    $this->author = $this->fixtures->kpi->authorActor();
    $this->reviewer = $this->fixtures->kpi->reviewerActor();
    $this->all = PeriodFixtures::everything();

    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-001');
    AccessFixtures::employee(null, 'PROD-WOVEN', number: 'W-002');

    $quality = $this->fixtures->kpi('IM-QUALITY', 'PROD-WOVEN', ['decimal_places' => 2]);
    $team = $this->fixtures->kpi('IM-TEAM', 'PROD-WOVEN', ['evaluation_subject' => 'team']);
    $this->fixtures->publishedTemplate('T-IM-WOVEN', 'PROD-WOVEN', [$quality->id => '60', $team->id => '40']);
    $this->period = $this->fixtures->opened();

    $this->csv = fn (array $lines, string $header = 'kpi_code,subject_type,subject_code,unit,target_value,source_note'): string => implode("\n", [$header, ...$lines])."\n";
    $this->rowsOf = fn (TargetImportBatch $batch): array => TargetImportRow::query()->where('target_import_batch_id', $batch->id)->orderBy('row_number')->get()
        ->mapWithKeys(fn (TargetImportRow $row): array => [$row->row_number => [$row->status, $row->errors ?? []]])
        ->all();
});

test('a valid file is staged with a summary, committed atomically, and committing again changes nothing', function (): void {
    $contents = ($this->csv)([
        'IM-QUALITY,employee,W-001,percent,97.5,Rapat target Oktober',
        'IM-QUALITY,employee,W-002,percent,,',
        'IM-TEAM,unit,PROD-WOVEN,percent,90,',
    ]);

    $batch = $this->imports->stage($this->author, $this->period, $this->all, 'C:\\fakepath\\target november.csv', $contents);

    expect($batch->status)->toBe(TargetImportBatch::STATUS_STAGED)
        ->and($batch->file_name)->toBe('target november.csv')
        ->and($batch->file_checksum)->toBe(hash('sha256', $contents))
        ->and($batch->summary)->toMatchArray(['rows' => 3, 'valid' => 2, 'unchanged' => 0, 'skipped' => 1, 'error' => 0, 'new_targets' => 2])
        ->and(($this->rowsOf)($batch))->toBe([2 => ['valid', []], 3 => ['skipped', []], 4 => ['valid', []]])
        // Staging alone never touches the targets.
        ->and(PeriodTargetVersion::query()->count())->toBe(0);

    $committed = $this->imports->commit($this->author, $batch->id, $batch->lock_version, $this->all, null);
    $versions = PeriodTargetVersion::query()->orderBy('id')->get();

    expect($committed->status)->toBe(TargetImportBatch::STATUS_COMMITTED)
        ->and($versions)->toHaveCount(2)
        ->and($versions->every(fn (PeriodTargetVersion $version): bool => $version->status === TargetStatus::Draft && $version->source === 'import' && $version->target_import_batch_id === $batch->id))->toBeTrue()
        ->and($versions[0]->target_value)->toBe('97.5000')
        ->and($versions[0]->source_note)->toBe('Rapat target Oktober')
        ->and(OutboxMessage::query()->where('event_type', 'ImportCommitted')->where('aggregate_id', $batch->public_id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'target_import.committed')->where('entity_id', $batch->public_id)->value('after_values'))->toMatchArray(['created' => 2, 'updated' => 0, 'rows' => 3]);

    // Replay of the same commit: same result, no second application.
    $replayed = $this->imports->commit($this->author, $batch->id, $batch->lock_version, $this->all, null);

    expect($replayed->status)->toBe(TargetImportBatch::STATUS_COMMITTED)
        ->and(PeriodTargetVersion::query()->count())->toBe(2)
        ->and(OutboxMessage::query()->where('event_type', 'ImportCommitted')->count())->toBe(1)
        ->and(AccessFixtures::violatedRule(fn () => $this->imports->stage($this->author, $this->period, $this->all, 'lagi.csv', $contents)))->toBe('import_already_committed');
});

test('invalid rows are reported per row and the batch cannot be committed, not even partially', function (): void {
    $batch = $this->imports->stage($this->author, $this->period, $this->all, 'salah.csv', ($this->csv)([
        'IM-QUALITY,employee,W-001,percent,97.555,',
        'IM-QUALITY,employee,W-002,pieces,97,',
        'IM-UNKNOWN,employee,W-001,percent,90,',
        'IM-TEAM,team,PROD-WOVEN,percent,90,',
        'IM-TEAM,unit,PROD-WOVEN,percent,90,',
        'IM-TEAM,unit,PROD-WOVEN,percent,91,',
        ',employee,,percent,1,',
    ]));

    $rows = ($this->rowsOf)($batch);

    expect($batch->error_count)->toBe(7)
        ->and($batch->valid_count)->toBe(0)
        ->and($rows[2][1])->toBe(['KPI ini menerima paling banyak 2 angka di belakang koma.'])
        ->and($rows[3][1])->toBe(['Satuan target (pieces) tidak sesuai dengan satuan KPI (percent).'])
        ->and($rows[4][1])->toBe(['KPI untuk subjek ini tidak ditemukan pada periode ini dalam scope Anda.'])
        ->and($rows[5][1])->toBe(['subject_type harus "employee" atau "unit".'])
        ->and($rows[6][1])->toBe(['Duplikat: target yang sama muncul lagi pada baris 7.'])
        ->and($rows[7][1])->toBe(['Duplikat: target yang sama sudah ada pada baris 6.'])
        ->and($rows[8][1])->toBe(['kpi_code wajib diisi.', 'subject_code wajib diisi.'])
        ->and(AccessFixtures::violatedRule(fn () => $this->imports->commit($this->author, $batch->id, $batch->lock_version, $this->all, null)))->toBe('import_has_errors')
        ->and(PeriodTargetVersion::query()->count())->toBe(0)
        ->and($batch->fresh()?->status)->toBe(TargetImportBatch::STATUS_STAGED);
});

test('one invalid row blocks the valid rows of the same file', function (): void {
    $batch = $this->imports->stage($this->author, $this->period, $this->all, 'campur.csv', ($this->csv)([
        'IM-QUALITY,employee,W-001,percent,97,',
        'IM-QUALITY,employee,W-002,percent,abc,',
    ]));

    expect($batch->valid_count)->toBe(1)
        ->and($batch->error_count)->toBe(1)
        ->and(AccessFixtures::violatedRule(fn () => $this->imports->commit($this->author, $batch->id, $batch->lock_version, $this->all, null)))->toBe('import_has_errors')
        ->and(PeriodTargetVersion::query()->count())->toBe(0);
});

test('a commit is rolled back completely when a target changed after staging', function (): void {
    $batch = $this->imports->stage($this->author, $this->period, $this->all, 'basi.csv', ($this->csv)([
        'IM-QUALITY,employee,W-001,percent,97,',
        'IM-QUALITY,employee,W-002,percent,96,',
        'IM-TEAM,unit,PROD-WOVEN,percent,90,',
    ]));

    // Someone enters and finalizes the last target by hand in the meantime.
    $this->fixtures->approvedTarget($this->period, $this->fixtures->target($this->period, 'IM-TEAM', 'PROD-WOVEN'), '88');

    expect(AccessFixtures::violatedRule(fn () => $this->imports->commit($this->author, $batch->id, $batch->lock_version, $this->all, null)))->toBe('import_stale')
        // Rows 2 and 3 were still valid, but nothing of the file was applied.
        ->and(PeriodTargetVersion::query()->where('source', 'import')->count())->toBe(0)
        ->and(PeriodTargetVersion::query()->count())->toBe(1)
        ->and($batch->fresh()?->status)->toBe(TargetImportBatch::STATUS_STAGED)
        ->and(OutboxMessage::query()->where('event_type', 'ImportCommitted')->count())->toBe(0);

    // Staging the same file again validates against the current targets.
    $again = $this->imports->stage($this->author, $this->period, $this->all, 'basi.csv', ($this->csv)([
        'IM-QUALITY,employee,W-001,percent,97,',
        'IM-QUALITY,employee,W-002,percent,96,',
        'IM-TEAM,unit,PROD-WOVEN,percent,90,',
    ]));

    expect($again->id)->not->toBe($batch->id)
        ->and($batch->fresh()?->status)->toBe(TargetImportBatch::STATUS_DISCARDED)
        ->and(($this->rowsOf)($again)[4][1][0])->toContain('Target sudah final');
});

test('import updates an existing draft, skips unchanged rows, and never overwrites a concurrent draft change', function (): void {
    $first = $this->fixtures->target($this->period, 'IM-QUALITY', 'W-001');
    $second = $this->fixtures->target($this->period, 'IM-QUALITY', 'W-002');
    $targets = app(TargetService::class);
    $targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($first, '90'), $this->fixtures->entry($second, '91')]);

    $batch = $this->imports->stage($this->author, $this->period, $this->all, 'ubah.csv', ($this->csv)([
        'IM-QUALITY,employee,W-001,percent,90,',
        'IM-QUALITY,employee,W-002,percent,95,',
    ]));

    expect(($this->rowsOf)($batch))->toBe([2 => ['unchanged', []], 3 => ['valid', []]]);

    $draft = PeriodTargetVersion::query()->where('period_target_id', $second->id)->firstOrFail();
    $targets->saveDrafts($this->reviewer, $this->period->id, $this->all, [$this->fixtures->entry($second, '93', expectedLock: $draft->lock_version)]);

    expect(AccessFixtures::violatedRule(fn () => $this->imports->commit($this->author, $batch->id, $batch->lock_version, $this->all, null)))->toBe('import_stale')
        ->and($draft->fresh()?->target_value)->toBe('93.0000');
});

test('a stale or discarded batch cannot be committed', function (): void {
    $batch = $this->imports->stage($this->author, $this->period, $this->all, 'batal.csv', ($this->csv)(['IM-QUALITY,employee,W-001,percent,97,']));

    expect(fn () => $this->imports->commit($this->author, $batch->id, $batch->lock_version + 5, $this->all, null))->toThrow(ConcurrencyConflict::class)
        ->and(PeriodTargetVersion::query()->count())->toBe(0);

    $discarded = $this->imports->discard($this->author, $batch->id, $batch->lock_version);

    expect($discarded->status)->toBe(TargetImportBatch::STATUS_DISCARDED)
        ->and(AccessFixtures::violatedRule(fn () => $this->imports->commit($this->author, $batch->id, $discarded->lock_version, $this->all, null)))->toBe('import_not_staged')
        ->and(AccessFixtures::violatedRule(fn () => $this->imports->discard($this->author, $batch->id, $discarded->lock_version)))->toBe('import_not_staged');
});

test('files that cannot be read as the expected CSV are refused before staging', function (string $contents, string $rule): void {
    expect(AccessFixtures::violatedRule(fn () => $this->imports->stage($this->author, $this->period, $this->all, 'x.csv', $contents)))->toBe($rule)
        ->and(TargetImportBatch::query()->count())->toBe(0);
})->with([
    'missing required column' => ["kpi_code,subject_type,subject_code,target_value\nIM-QUALITY,employee,W-001,97\n", 'import_invalid_file'],
    'not UTF-8' => ["kpi_code,subject_type,subject_code,unit,target_value\nIM-QUALITY,employee,W-001,percent,\xE9\n", 'import_invalid_file'],
    'header only' => ["kpi_code,subject_type,subject_code,unit,target_value\n\n", 'import_empty'],
    'too large' => [str_repeat('x', TargetImportService::MAX_BYTES + 1), 'import_too_large'],
]);

test('semicolon separated files with a BOM and extra columns are accepted', function (): void {
    $contents = "\xEF\xBB\xBFKPI_Code;kpi_name;Subject_Type;subject_code;subject_name;unit;target_value;catatan\r\nim-quality;Nama KPI;Employee;w-001;Pegawai;percent;97.25;diabaikan\r\n";

    $batch = $this->imports->stage($this->author, $this->period, $this->all, 'excel.csv', $contents);

    expect(($this->rowsOf)($batch))->toBe([2 => ['valid', []]])
        ->and(TargetImportRow::query()->where('target_import_batch_id', $batch->id)->value('target_value'))->toBe('97.2500');
});

test('import after the cut-off needs a reason and flags the drafts', function (): void {
    $period = $this->fixtures->opened(['code' => 'P-2026-12', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31', 'deadlines' => ['target_setting' => '2026-10-11']]);
    $batch = $this->imports->stage($this->author, $period, $this->all, 'telat.csv', ($this->csv)(['IM-QUALITY,employee,W-001,percent,97,']));

    expect(AccessFixtures::violatedRule(fn () => $this->imports->commit($this->author, $batch->id, $batch->lock_version, $this->all, null)))->toBe('cutoff_reason_required')
        ->and(PeriodTargetVersion::query()->count())->toBe(0);

    $this->imports->commit($this->author, $batch->id, $batch->lock_version, $this->all, 'Data baru diterima dari PPIC');

    expect(PeriodTargetVersion::query()->firstOrFail()->after_cutoff)->toBeTrue()
        ->and(PeriodTargetVersion::query()->firstOrFail()->change_reason)->toBe('Data baru diterima dari PPIC');
});

test('the fill-in file lists the targets a file may set and neutralizes formulas', function (): void {
    $this->fixtures->approvedTarget($this->period, $this->fixtures->target($this->period, 'IM-TEAM', 'PROD-WOVEN'), '90');
    app(TargetService::class)->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($this->fixtures->target($this->period, 'IM-QUALITY', 'W-001'), '97.5')]);

    $rows = $this->imports->templateRows($this->period, $this->all);

    expect($rows[0])->toBe(TargetCsv::COLUMNS)
        ->and(array_column(array_slice($rows, 1), 0))->toBe(['IM-QUALITY', 'IM-QUALITY'])
        ->and($rows[1][3])->toBe('W-001')
        ->and($rows[1][6])->toBe('97.5')
        ->and($rows[2][6])->toBe('')
        ->and(TargetCsv::cell('=HYPERLINK("http://x")'))->toBe('\'=HYPERLINK("http://x")')
        ->and(TargetCsv::cell('@cmd'))->toBe("'@cmd")
        ->and(TargetCsv::cell('-5.5'))->toBe('-5.5')
        ->and(TargetCsv::cell('Woven'))->toBe('Woven');
});

test('a commit is refused when a target of an unchanged row changed after staging', function (): void {
    $first = $this->fixtures->target($this->period, 'IM-QUALITY', 'W-001');
    $targets = app(TargetService::class);
    $targets->saveDrafts($this->author, $this->period->id, $this->all, [$this->fixtures->entry($first, '90')]);

    $batch = $this->imports->stage($this->author, $this->period, $this->all, 'sama.csv', ($this->csv)([
        'IM-QUALITY,employee,W-001,percent,90,',
        'IM-QUALITY,employee,W-002,percent,95,',
    ]));

    $draft = PeriodTargetVersion::query()->where('period_target_id', $first->id)->firstOrFail();
    $targets->saveDrafts($this->reviewer, $this->period->id, $this->all, [$this->fixtures->entry($first, '80', expectedLock: $draft->lock_version)]);

    expect(AccessFixtures::violatedRule(fn () => $this->imports->commit($this->author, $batch->id, $batch->lock_version, $this->all, null)))->toBe('import_stale')
        ->and(PeriodTargetVersion::query()->where('source', 'import')->count())->toBe(0);
});

test('a file of very many tiny rows is refused while it is read, and closed periods take no import', function (): void {
    $many = "kpi_code,subject_type,subject_code,unit,target_value\n".str_repeat("a\n", TargetImportService::MAX_ROWS + 1);

    expect(AccessFixtures::violatedRule(fn () => $this->imports->stage($this->author, $this->period, $this->all, 'banyak.csv', $many)))->toBe('import_invalid_file');

    $draft = $this->fixtures->draft(['code' => 'P-2026-12', 'startsOn' => '2026-12-01', 'endsOn' => '2026-12-31']);

    expect(AccessFixtures::violatedRule(fn () => $this->imports->stage($this->author, $draft, $this->all, 'draft.csv', ($this->csv)(['IM-QUALITY,employee,W-001,percent,90,']))))->toBe('period_not_accepting_targets')
        ->and(TargetImportBatch::query()->count())->toBe(0);
});

test('staging the same file does not discard a batch staged by another account', function (): void {
    $contents = ($this->csv)(['IM-QUALITY,employee,W-001,percent,97,']);
    $mine = $this->imports->stage($this->author, $this->period, $this->all, 'a.csv', $contents);
    $theirs = $this->imports->stage($this->reviewer, $this->period, $this->all, 'a.csv', $contents);

    expect($mine->fresh()?->status)->toBe(TargetImportBatch::STATUS_STAGED)
        ->and($theirs->status)->toBe(TargetImportBatch::STATUS_STAGED);
});
