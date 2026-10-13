<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Infrastructure\Persistence\Models;

use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One data row of a staged actual import with its validation result.
 *
 * @property int $id
 * @property int $actual_import_batch_id
 * @property int $row_number
 * @property string $status
 * @property array<string, string> $raw
 * @property list<string>|null $errors
 * @property int|null $period_target_id
 * @property string|null $window_start
 * @property string|null $window_end
 * @property string|null $entry_kind
 * @property string|null $actual_value
 * @property string|null $unit
 * @property string|null $source_system
 * @property string|null $source_reference
 * @property string|null $note
 * @property string|null $expected_state
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ActualImportRow extends Model
{
    public const STATUS_VALID = 'valid';

    public const STATUS_UNCHANGED = 'unchanged';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_ERROR = 'error';

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_VALID => 'Valid',
        self::STATUS_UNCHANGED => 'Tidak berubah',
        self::STATUS_SKIPPED => 'Dilewati (nilai kosong)',
        self::STATUS_ERROR => 'Error',
    ];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'errors' => 'array',
            'row_number' => 'integer',
            'actual_value' => 'decimal:4',
            'window_start' => BusinessDate::class,
            'window_end' => BusinessDate::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
