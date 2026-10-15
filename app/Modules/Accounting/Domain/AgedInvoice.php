<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * One invoice as of a date: what is still owed and how far past due it is.
 * The bucket is null when nothing is owed or more than the invoice was
 * allocated.
 */
final readonly class AgedInvoice
{
    public const PAID = 'paid';

    public const OVER_ALLOCATED = 'over_allocated';

    public function __construct(
        public string $invoiceNumber,
        public string $amount,
        public string $allocated,
        public string $outstanding,
        public int $daysPastDue,
        public ?string $bucket,
        public string $state,
    ) {}
}
