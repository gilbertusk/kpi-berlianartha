<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Persistence\Models;

use App\Modules\Shared\Infrastructure\Persistence\Casts\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a receivables snapshot: an invoice, a payment allocation to
 * an invoice, or a tax invoice reference.
 *
 * @property int $id
 * @property int $ar_snapshot_id
 * @property int $source_line
 * @property string $document_type invoice|allocation|tax_invoice
 * @property string $document_number
 * @property string|null $related_document
 * @property string|null $customer_code
 * @property string|null $customer_name
 * @property string $document_date
 * @property string|null $due_date
 * @property string|null $currency
 * @property string|null $amount
 * @property string|null $status
 * @property string|null $status_reason
 * @property string|null $collection_owner
 * @property string|null $next_action
 * @property string|null $next_action_date
 * @property string $row_hash
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ArSnapshot $snapshot
 */
final class ArDocument extends Model
{
    public const INVOICE = 'invoice';

    public const ALLOCATION = 'allocation';

    public const TAX_INVOICE = 'tax_invoice';

    /** The invoice status value that marks a dispute in the source contract. */
    public const DISPUTED = 'disputed';

    protected $guarded = [];

    /**
     * @return BelongsTo<ArSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ArSnapshot::class, 'ar_snapshot_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_date' => BusinessDate::class,
            'due_date' => BusinessDate::class,
            'next_action_date' => BusinessDate::class,
            'amount' => 'decimal:2',
            'source_line' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
