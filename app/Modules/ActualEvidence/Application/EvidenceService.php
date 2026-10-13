<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Application;

use App\Models\User;
use App\Modules\ActualEvidence\Domain\EvidenceRules;
use App\Modules\ActualEvidence\Domain\ScanStatus;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\EvidenceFile;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActual;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\KpiActualVersion;
use App\Modules\PeriodAssignment\Domain\PeriodStatus;
use App\Modules\Shared\Application\Audit\Actor;
use App\Modules\Shared\Application\Audit\AuditEntry;
use App\Modules\Shared\Application\Audit\AuditRecorder;
use App\Modules\Shared\Application\Storage\ObjectMetadataStore;
use App\Modules\Shared\Application\Storage\PendingObjectMetadata;
use App\Modules\Shared\Application\Transactions\TransactionBoundary;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Persistence\Models\StoredObjectMetadata;
use Carbon\CarbonImmutable;
use finfo;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Evidence files of an actual (ADR-0004).
 *
 * - The file type is decided from its content; the browser-reported type
 *   and the client file name are never trusted. The name is display text
 *   only; the storage key is generated.
 * - The binary goes to the private evidence disk. PostgreSQL holds
 *   metadata, size, and SHA-256 only.
 * - A file is quarantined until the scanner clears it and is not served
 *   before that. No antivirus is integrated yet, so outside local/test
 *   environments uploaded files stay quarantined.
 * - Files are opened through a signed link that expires quickly and is bound
 *   to the requesting account; the download re-checks authorization.
 * - Evidence can be attached or removed only while the entry is editable.
 *   Removing keeps the binary: retention is not decided (DEC-010).
 */
final readonly class EvidenceService
{
    private const CLASSIFICATION = 'confidential';

    public function __construct(
        private TransactionBoundary $transactions,
        private AuditRecorder $audit,
        private ObjectMetadataStore $objects,
        private FilesystemFactory $filesystems,
        private MalwareScanner $scanner,
        private EvidenceRules $rules,
        private string $disk,
        private int $linkTtlSeconds,
    ) {}

    public function attach(Actor $actor, int $actualId, string $clientName, string $contents): EvidenceFile
    {
        $checksum = hash('sha256', $contents);
        $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream';
        $key = null;

        try {
            return $this->transactions->run(function () use ($actor, $actualId, $clientName, $contents, $checksum, $detectedMime, &$key): EvidenceFile {
                $actual = $this->editableActual($actualId);
                $existing = EvidenceFile::query()->where('kpi_actual_id', $actual->id)->whereNull('removed_at')->get();

                try {
                    $extension = $this->rules->accept($clientName, strlen($contents), $detectedMime, substr($contents, 0, 16), $existing->count());

                    if ($extension === 'xlsx') {
                        EvidenceRules::assertWorkbook($contents);
                    }
                } catch (InvalidArgumentException $exception) {
                    throw new BusinessRuleViolation('evidence_rejected', $exception->getMessage());
                }

                if ($existing->contains('checksum', $checksum)) {
                    throw new BusinessRuleViolation('evidence_duplicate', 'File yang sama sudah dilampirkan pada aktual ini.');
                }

                $name = EvidenceRules::safeName($clientName);
                $key = 'evidence/'.$actual->target->period->public_id.'/'.$actual->public_id.'/'.Str::ulid().'.'.$extension;

                if ($this->filesystems->disk($this->disk)->put($key, $contents) !== true) {
                    throw new BusinessRuleViolation('evidence_storage_failed', 'File bukti tidak dapat disimpan. Coba lagi.');
                }

                $stream = $this->filesystems->disk($this->disk)->readStream($key);
                $scan = is_resource($stream) ? $this->scanner->scan($stream) : ScanStatus::Quarantined;

                if (is_resource($stream)) {
                    fclose($stream);
                }

                $object = $this->objects->register(new PendingObjectMetadata(
                    disk: $this->disk,
                    path: $key,
                    originalName: $name,
                    contentType: $detectedMime,
                    byteSize: strlen($contents),
                    checksumAlgorithm: 'sha256',
                    checksum: $checksum,
                    classification: self::CLASSIFICATION,
                    ownerType: 'kpi_actual',
                    ownerId: $actual->public_id,
                    sourceSystem: null,
                    sourceReference: null,
                    createdBy: $actor->audit->actorIdentifier,
                    metadata: ['scan_status' => $scan->value, 'scanner' => $this->scanner->name()],
                ));

                $evidence = EvidenceFile::query()->create([
                    'kpi_actual_id' => $actual->id,
                    'stored_object_id' => StoredObjectMetadata::query()->where('public_id', $object->publicId)->value('id'),
                    'original_name' => $name,
                    'detected_mime' => $detectedMime,
                    'byte_size' => strlen($contents),
                    'checksum' => $checksum,
                    'scan_status' => $scan,
                    'scanner' => $this->scanner->name(),
                    'scanned_at' => in_array($scan, [ScanStatus::Clean, ScanStatus::Infected], true) ? CarbonImmutable::now() : null,
                    'uploaded_by_user_id' => $actor->userId,
                ]);

                $this->record($actor, $actual, 'evidence.attached', null, [
                    'evidence_id' => $evidence->public_id,
                    'file_name' => $name,
                    'detected_mime' => $detectedMime,
                    'byte_size' => strlen($contents),
                    'checksum' => $checksum,
                    'scan_status' => $scan->value,
                ]);

                return $evidence;
            }, 1);
        } catch (Throwable $exception) {
            // The metadata rolled back; do not leave an unreferenced object.
            // If the row is there after all (commit outcome unknown), keep it.
            try {
                if ($key !== null && ! StoredObjectMetadata::query()->where('disk', $this->disk)->where('path', $key)->exists()) {
                    $this->filesystems->disk($this->disk)->delete($key);
                }
            } catch (Throwable $cleanupFailure) {
                report($cleanupFailure);
            }

            throw $exception;
        }
    }

    public function remove(Actor $actor, int $evidenceId): void
    {
        $this->transactions->run(function () use ($actor, $evidenceId): void {
            $evidence = EvidenceFile::query()->lockForUpdate()->findOrFail($evidenceId);
            $actual = $this->editableActual($evidence->kpi_actual_id);

            if ($evidence->removed_at !== null) {
                throw new BusinessRuleViolation('evidence_removed', 'File bukti ini sudah dilepas.');
            }

            $evidence->forceFill(['removed_at' => CarbonImmutable::now(), 'removed_by_user_id' => $actor->userId])->save();

            $this->record($actor, $actual, 'evidence.removed', ['evidence_id' => $evidence->public_id, 'file_name' => $evidence->original_name], null);
        });
    }

    /**
     * A link that expires quickly and only works for this account. The
     * download endpoint authorizes again when the link is used.
     */
    public function linkFor(EvidenceFile $evidence, User $user): string
    {
        $this->assertServable($evidence);

        return URL::temporarySignedRoute('evidence.download', CarbonImmutable::now()->addSeconds($this->linkTtlSeconds), [
            'evidence' => $evidence->public_id,
            'viewer' => $user->public_id,
        ]);
    }

    /**
     * @return resource
     */
    public function open(Actor $actor, EvidenceFile $evidence)
    {
        $this->assertServable($evidence);

        $stream = $this->filesystems->disk($evidence->storedObject->disk)->readStream($evidence->storedObject->path);

        if (! is_resource($stream)) {
            throw new BusinessRuleViolation('evidence_unavailable', 'File bukti tidak ditemukan di penyimpanan.');
        }

        $this->transactions->run(fn () => $this->record($actor, $evidence->actual, 'evidence.downloaded', null, [
            'evidence_id' => $evidence->public_id,
            'checksum' => $evidence->checksum,
        ]));

        return $stream;
    }

    private function assertServable(EvidenceFile $evidence): void
    {
        if ($evidence->removed_at !== null || ! $evidence->scan_status->isServable()) {
            throw new BusinessRuleViolation('evidence_not_servable', 'File bukti belum lolos pemindaian malware dan tidak dapat dibuka.');
        }
    }

    private function editableActual(int $actualId): KpiActual
    {
        $actual = KpiActual::query()->with('target.period')->lockForUpdate()->findOrFail($actualId);
        // Read under lock: a concurrent submit must not be missed.
        $latest = KpiActualVersion::query()->where('kpi_actual_id', $actual->id)->orderByDesc('version_number')->lockForUpdate()->first();

        if ($actual->target->period->status !== PeriodStatus::InProgress || $latest === null || ! $latest->status->isEditable()) {
            throw new BusinessRuleViolation('evidence_locked', 'Bukti hanya dapat diubah selama aktual berstatus draft atau ditolak pada periode In progress.');
        }

        return $actual;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(Actor $actor, KpiActual $actual, string $action, ?array $before, ?array $after): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            entityType: 'kpi_actual',
            entityId: $actual->public_id,
            before: $before,
            after: $after,
            reason: null,
            context: $actor->audit,
        ));
    }
}
