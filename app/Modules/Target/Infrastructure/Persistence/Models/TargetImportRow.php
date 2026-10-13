<?php

declare(strict_types=1);

namespace App\Modules\Target\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One data row of a staged import with its validation result.
 *
 * @property int $id
 * @property int $target_import_batch_id
 * @property int $row_number line number in the uploaded file
 * @property string $status
 * @property array<string, string> $raw
 * @property list<string>|null $errors
 * @property int|null $period_target_id
 * @property string|null $target_value
 * @property string|null $unit
 * @property string|null $source_note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class TargetImportRow extends Model
{
    /** Will create or update a draft target on commit. */
    public const STATUS_VALID = 'valid';

    /** Same value as the current draft; nothing to do. */
    public const STATUS_UNCHANGED = 'unchanged';

    /** No target value in the row; ignored. */
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
            'target_value' => 'decimal:4',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
