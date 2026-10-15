<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Ages invoices on an as-of date, in exact decimals.
 *
 * - Outstanding = invoice amount − allocations dated on or before the as-of
 *   date. A partial payment lowers the outstanding; a dispute does not
 *   change it (the dispute is shown, not netted).
 * - Days past due = as-of date − due date in calendar days; zero or less is
 *   not yet due.
 * - Paid in full: outstanding zero, not aged.
 * - Allocations above the invoice amount: the credit balance is reported as
 *   an exception and not aged, because the treatment of overpayments and
 *   credit notes is not decided.
 */
final class AgingCalculator
{
    public const OPEN = 'open';

    /**
     * @param  list<string>  $allocations  amounts allocated to the invoice up to the as-of date
     */
    public static function age(AgingBuckets $buckets, string $asOf, string $invoiceNumber, string $amount, ?string $dueDate, array $allocations): AgedInvoice
    {
        $total = BigDecimal::of($amount);
        $allocated = BigDecimal::zero();

        foreach ($allocations as $allocation) {
            $allocated = $allocated->plus(BigDecimal::of($allocation));
        }

        $outstanding = $total->minus($allocated);
        $days = $dueDate === null ? 0 : self::days($dueDate, $asOf);

        $state = match (true) {
            $outstanding->isZero() => AgedInvoice::PAID,
            $outstanding->isNegative() => AgedInvoice::OVER_ALLOCATED,
            default => self::OPEN,
        };

        return new AgedInvoice(
            $invoiceNumber,
            (string) $total->toScale(2),
            (string) $allocated->toScale(2),
            (string) $outstanding->toScale(2),
            $days,
            $state === self::OPEN ? $buckets->bucketFor($days) : null,
            $state,
        );
    }

    /**
     * Calendar days from the due date to the as-of date (both business dates).
     */
    public static function days(string $from, string $to): int
    {
        $utc = new DateTimeZone('UTC');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $utc);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $utc);

        if ($start === false || $end === false) {
            throw new InvalidArgumentException('Tanggal aging tidak valid.');
        }

        return (int) (($end->getTimestamp() - $start->getTimestamp()) / 86400);
    }
}
