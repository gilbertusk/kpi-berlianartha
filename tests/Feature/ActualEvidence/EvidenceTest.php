<?php

declare(strict_types=1);

use App\Modules\ActualEvidence\Application\ActualAccess;
use App\Modules\ActualEvidence\Application\ActualEntry;
use App\Modules\ActualEvidence\Application\ActualService;
use App\Modules\ActualEvidence\Application\EvidenceService;
use App\Modules\ActualEvidence\Application\MalwareScanner;
use App\Modules\ActualEvidence\Domain\EvidenceRules;
use App\Modules\ActualEvidence\Domain\ScanStatus;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\EvidenceFile;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\ActualEvidence\Infrastructure\Scanning\UnavailableScanner;
use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Infrastructure\Persistence\Models\AuditLog;
use App\Modules\Shared\Infrastructure\Persistence\Models\StoredObjectMetadata;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
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
    $this->hr = $this->fixtures->kpi->authorActor();

    $this->staffUser = AccessFixtures::user('Staff Woven');
    AccessFixtures::employee($this->staffUser, 'PROD-WOVEN', number: 'W-001');
    AccessFixtures::grant($this->staffUser, Role::Employee);
    AccessFixtures::employee(null, 'ACC-AR', number: 'AR-001');

    $quality = $this->fixtures->kpi('EV-QUALITY', 'PROD-WOVEN');
    $dso = $this->fixtures->kpi('EV-DSO', 'ACC-AR');
    $this->fixtures->publishedTemplate('T-EV-WOVEN', 'PROD-WOVEN', [$quality->id => '100']);
    $this->fixtures->publishedTemplate('T-EV-AR', 'ACC-AR', [$dso->id => '100']);
    $this->period = $this->fixtures->inProgress();

    $target = $this->fixtures->target($this->period, 'EV-QUALITY', 'W-001');
    app(ActualService::class)->saveDrafts($this->hr, $this->period->id, ActualAccess::everything(), [
        new ActualEntry('a', $target->id, '2026-11-01', '2026-11-30', '95', 'percent'),
    ]);

    $this->actual = KpiActual::query()->firstOrFail();
    $this->files = fn (): EvidenceService => app(EvidenceService::class);
    $this->pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    $this->png = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa7\x35\x81\x84\x00\x00\x00\x00IEND\xaeB`\x82";
});

test('an accepted file is stored privately under a generated key with its checksum', function (): void {
    $evidence = ($this->files)()->attach($this->hr, $this->actual->id, 'Laporan QC November.pdf', $this->pdf);
    $object = StoredObjectMetadata::query()->findOrFail($evidence->stored_object_id);

    expect($evidence->original_name)->toBe('Laporan QC November.pdf')
        ->and($evidence->detected_mime)->toBe('application/pdf')
        ->and($evidence->checksum)->toBe(hash('sha256', $this->pdf))
        ->and($evidence->byte_size)->toBe(strlen($this->pdf))
        ->and($evidence->scan_status)->toBe(ScanStatus::AcceptedUnscanned)
        ->and($object->disk)->toBe('s3')
        ->and($object->classification)->toBe('confidential')
        ->and($object->path)->toStartWith("evidence/{$this->period->public_id}/{$this->actual->public_id}/")
        ->and($object->path)->not->toContain('Laporan')
        ->and(Storage::disk('s3')->get($object->path))->toBe($this->pdf)
        ->and(AuditLog::query()->where('action', 'evidence.attached')->where('entity_id', $this->actual->public_id)->value('after_values'))->toMatchArray(['file_name' => 'Laporan QC November.pdf', 'checksum' => hash('sha256', $this->pdf)]);
});

test('files are judged by their content, not by their name or the browser', function (string $name, string $contents, string $rule): void {
    expect(AccessFixtures::violatedRule(fn () => ($this->files)()->attach($this->hr, $this->actual->id, $name, $contents)))->toBe($rule)
        ->and(EvidenceFile::query()->count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
})->with([
    'executable renamed to pdf' => ['laporan.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF", 'evidence_rejected'],
    'script with image extension' => ['foto.png', '<?php system($_GET["c"]); ?>', 'evidence_rejected'],
    'html pretending to be pdf' => ['dokumen.pdf', '<html><script>alert(1)</script></html>', 'evidence_rejected'],
    'double extension' => ['laporan.pdf.exe', "%PDF-1.4\n%%EOF\n", 'evidence_rejected'],
    'php extension' => ['shell.php', "%PDF-1.4\n%%EOF\n", 'evidence_rejected'],
    'no extension' => ['laporan', "%PDF-1.4\n%%EOF\n", 'evidence_rejected'],
    'empty file' => ['kosong.pdf', '', 'evidence_rejected'],
]);

test('a malicious file name never reaches the storage key and is neutralized for display', function (string $clientName, string $displayed): void {
    $evidence = ($this->files)()->attach($this->hr, $this->actual->id, $clientName, $this->pdf);
    $path = StoredObjectMetadata::query()->findOrFail($evidence->stored_object_id)->path;

    expect($evidence->original_name)->toBe($displayed)
        ->and($path)->toMatch('#^evidence/[0-9A-Z]{26}/[0-9A-Z]{26}/[0-9A-Z]{26}\.pdf$#');
})->with([
    'path traversal' => ['../../../../etc/passwd.pdf', 'passwd.pdf'],
    'windows path' => ['C:\\Users\\x\\..\\..\\boot.pdf', 'boot.pdf'],
    'null byte and control characters' => ["lap\x00oran\r\n.pdf", 'laporan.pdf'],
    'markup' => ['<img src=x onerror=alert(1)>.pdf', 'img src=x onerror=alert(1).pdf'],
]);

test('size, count, and duplicate limits are enforced on the server', function (): void {
    Config::set('evidence.max_bytes', 200);
    Config::set('evidence.max_files_per_actual', 2);
    app()->forgetInstance(EvidenceRules::class);

    expect(AccessFixtures::violatedRule(fn () => ($this->files)()->attach($this->hr, $this->actual->id, 'besar.pdf', $this->pdf.str_repeat('x', 300))))->toBe('evidence_rejected');

    ($this->files)()->attach($this->hr, $this->actual->id, 'satu.pdf', $this->pdf);

    expect(AccessFixtures::violatedRule(fn () => ($this->files)()->attach($this->hr, $this->actual->id, 'salinan.pdf', $this->pdf)))->toBe('evidence_duplicate');

    ($this->files)()->attach($this->hr, $this->actual->id, 'dua.png', $this->png);

    expect(AccessFixtures::violatedRule(fn () => ($this->files)()->attach($this->hr, $this->actual->id, 'tiga.pdf', $this->pdf.'%')))->toBe('evidence_rejected')
        ->and(EvidenceFile::query()->count())->toBe(2)
        ->and(Storage::disk('s3')->allFiles())->toHaveCount(2);
});

test('without a scanner a file stays quarantined: it cannot be opened and does not clear verification', function (): void {
    Config::set('evidence.scanner', 'none');

    $evidence = ($this->files)()->attach($this->hr, $this->actual->id, 'laporan.pdf', $this->pdf);
    $actuals = app(ActualService::class);
    $actuals->submit($this->hr, $this->period->id, ActualAccess::everything());
    $leader = AccessFixtures::userWithRole(Role::TeamLeader, 'PROD-WOVEN');

    expect($evidence->scan_status)->toBe(ScanStatus::Quarantined)
        ->and($evidence->scanned_at)->toBeNull()
        ->and(AccessFixtures::violatedRule(fn () => ($this->files)()->linkFor($evidence, $this->fixtures->kpi->author)))->toBe('evidence_not_servable')
        ->and(AccessFixtures::violatedRule(fn () => ($this->files)()->open($this->hr, $evidence)))->toBe('evidence_not_servable')
        ->and(AccessFixtures::violatedRule(fn () => $actuals->verify(new Actor($leader->id, Actor::system('test')->audit), $this->period->id, ActualAccess::everything())))->toBe('evidence_not_cleared');
});

test('outside local and test environments the unscanned setting falls back to quarantine', function (): void {
    Config::set('evidence.scanner', 'local-unscanned');
    app()->detectEnvironment(fn (): string => 'production');

    // Fails closed: everything stays quarantined, nothing is served.
    expect(app(MalwareScanner::class))->toBeInstanceOf(UnavailableScanner::class);
});

test('evidence is downloaded through a short-lived signed link bound to an authorized account', function (): void {
    $evidence = ($this->files)()->attach($this->hr, $this->actual->id, 'laporan.pdf', $this->pdf);
    $author = $this->fixtures->kpi->author;
    $link = ($this->files)()->linkFor($evidence, $author);

    $response = $this->actingAs($author)->get($link);

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'attachment; filename=laporan.pdf');

    expect($response->streamedContent())->toBe($this->pdf)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(AuditLog::query()->where('action', 'evidence.downloaded')->where('entity_id', $this->actual->public_id)->count())->toBe(1);

    // The owner of the actual may open it through their own link.
    $this->actingAs($this->staffUser)->get(($this->files)()->linkFor($evidence, $this->staffUser))->assertOk();
});

test('a link does not work for another account, without a signature, after expiry, or outside the viewer scope', function (): void {
    $evidence = ($this->files)()->attach($this->hr, $this->actual->id, 'laporan.pdf', $this->pdf);
    $author = $this->fixtures->kpi->author;
    $link = ($this->files)()->linkFor($evidence, $author);

    $otherLeader = AccessFixtures::userWithRole(Role::TeamLeader, 'ACC-AR');
    $systemAdmin = AccessFixtures::userWithRole(Role::SystemAdmin);

    // Someone else's link, even for an account that could see the actual.
    $this->actingAs($this->staffUser)->get($link)->assertForbidden();
    // Unsigned or tampered URL.
    $this->actingAs($author)->get(route('evidence.download', ['evidence' => $evidence->public_id, 'viewer' => $author->public_id]))->assertForbidden();
    $this->actingAs($author)->get($link.'0')->assertForbidden();
    // An account whose scope does not include the actual, with its own validly signed link.
    $this->actingAs($otherLeader)->get(URL::temporarySignedRoute('evidence.download', now()->addMinutes(5), ['evidence' => $evidence->public_id, 'viewer' => $otherLeader->public_id]))->assertForbidden();
    $this->actingAs($systemAdmin)->get(URL::temporarySignedRoute('evidence.download', now()->addMinutes(5), ['evidence' => $evidence->public_id, 'viewer' => $systemAdmin->public_id]))->assertForbidden();
    // Guests are sent to login.
    auth()->logout();
    $this->get($link)->assertRedirect(route('login'));

    Date::setTestNow(CarbonImmutable::now()->addSeconds(config('evidence.link_ttl_seconds') + 1));
    $this->actingAs($author)->get($link)->assertForbidden();

    expect(AuditLog::query()->where('action', 'evidence.downloaded')->count())->toBe(0);
});

test('evidence is removed only while the entry is editable, and the binary is kept', function (): void {
    $evidence = ($this->files)()->attach($this->hr, $this->actual->id, 'laporan.pdf', $this->pdf);
    $second = ($this->files)()->attach($this->hr, $this->actual->id, 'foto.png', $this->png);

    ($this->files)()->remove($this->hr, $evidence->id);

    expect($evidence->fresh()?->removed_at)->not->toBeNull()
        ->and($this->actual->evidence()->count())->toBe(1)
        ->and(Storage::disk('s3')->allFiles())->toHaveCount(2)
        ->and(AccessFixtures::violatedRule(fn () => ($this->files)()->linkFor($evidence->fresh(), $this->fixtures->kpi->author)))->toBe('evidence_not_servable');

    app(ActualService::class)->submit($this->hr, $this->period->id, ActualAccess::everything());

    expect(AccessFixtures::violatedRule(fn () => ($this->files)()->remove($this->hr, $second->id)))->toBe('evidence_locked');
});

test('an xlsx evidence file must be a real workbook without macros', function (): void {
    $zip = function (array $entries): string {
        $path = tempnam(sys_get_temp_dir(), 'kpi-zip-');
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::OVERWRITE);

        foreach ($entries as $name) {
            $archive->addFromString($name, '<x/>');
        }

        $archive->close();
        $contents = (string) file_get_contents($path);
        unlink($path);

        return $contents;
    };

    expect(AccessFixtures::violatedRule(fn () => ($this->files)()->attach($this->hr, $this->actual->id, 'arsip.xlsx', $zip(['payload.class', 'META-INF/MANIFEST.MF']))))->toBe('evidence_rejected')
        ->and(AccessFixtures::violatedRule(fn () => ($this->files)()->attach($this->hr, $this->actual->id, 'macro.xlsx', $zip(['[Content_Types].xml', 'xl/workbook.xml', 'xl/vbaProject.bin']))))->toBe('evidence_rejected')
        ->and(Storage::disk('s3')->allFiles())->toBe([]);

    $evidence = ($this->files)()->attach($this->hr, $this->actual->id, 'rekap.xlsx', $zip(['[Content_Types].xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml']));

    expect($evidence->original_name)->toBe('rekap.xlsx');
});
