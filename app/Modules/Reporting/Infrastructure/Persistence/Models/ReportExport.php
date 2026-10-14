<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Reporting\Domain\Classification;
use App\Modules\Reporting\Domain\ExportStatus;
use App\Modules\Reporting\Domain\ReportFormat;
use App\Modules\Shared\Infrastructure\Persistence\Concerns\HasPublicUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One requested export and its private file.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $report_key
 * @property ReportFormat $format
 * @property array<string, string> $filters
 * @property string $request_fingerprint
 * @property ExportStatus $status
 * @property int $attempts
 * @property int $rows_written
 * @property string|null $failure_code
 * @property string|null $scope_summary
 * @property Classification $classification
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $file_name
 * @property int|null $byte_size
 * @property string|null $checksum_sha256
 * @property int $download_count
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $purged_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 */
final class ReportExport extends Model
{
    use HasPublicUlid;

    /** Safe explanations of why an export failed; nothing else is shown. */
    public const FAILURES = [
        'not_authorized' => 'Akses Anda ke laporan atau filter ini sudah tidak berlaku.',
        'requester_inactive' => 'Akun peminta sudah tidak aktif.',
        'invalid_request' => 'Filter laporan tidak lagi valid.',
        'too_many_rows_for_pdf' => 'Laporan terlalu besar untuk PDF. Persempit filter atau gunakan Excel.',
        'too_many_rows' => 'Laporan melebihi batas baris ekspor. Persempit filter.',
        'generation_failed' => 'Ekspor gagal dibuat. Coba ulang; bila berulang hubungi administrator.',
    ];

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function failureMessage(): ?string
    {
        return $this->failure_code === null ? null : (self::FAILURES[$this->failure_code] ?? self::FAILURES['generation_failed']);
    }

    public function isDownloadableAt(CarbonImmutable $at): bool
    {
        return $this->status === ExportStatus::Completed && $this->path !== null && $this->purged_at === null
            && $this->expires_at !== null && $this->expires_at->greaterThan($at);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => ReportFormat::class,
            'status' => ExportStatus::class,
            'classification' => Classification::class,
            'filters' => 'array',
            'attempts' => 'integer',
            'rows_written' => 'integer',
            'byte_size' => 'integer',
            'download_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
