<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\AgedInvoice;
use App\Modules\Accounting\Domain\AgingBuckets;
use App\Modules\Accounting\Domain\AgingCalculator;
use App\Modules\Accounting\Infrastructure\Persistence\Models\ArDocument;
use App\Modules\Accounting\Infrastructure\Persistence\Models\ArSnapshot;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Config;

/**
 * Aging and reconciliation of one snapshot, computed from its rows.
 */
final class ArQuery
{
    public static function buckets(): AgingBuckets
    {
        $config = Config::get('accounting.aging_buckets', AgingBuckets::PRD_DEFAULT);

        return AgingBuckets::fromArray(is_array($config) ? $config : AgingBuckets::PRD_DEFAULT);
    }

    /**
     * @return array{invoice_total: string, allocation_total: string, invoices: int, allocations: int, tax_invoices: int}
     */
    public static function totals(int $snapshotId): array
    {
        $sums = [ArDocument::INVOICE => BigDecimal::zero(), ArDocument::ALLOCATION => BigDecimal::zero(), ArDocument::TAX_INVOICE => BigDecimal::zero()];
        $counts = array_fill_keys(array_keys($sums), 0);

        // Added here, not with SQL SUM(): SQLite sums decimals as floats.
        foreach (ArDocument::query()->where('ar_snapshot_id', $snapshotId)->toBase()->select(['id', 'document_type', 'amount'])->lazyById(1000) as $row) {
            $type = (string) $row->document_type;
            $counts[$type]++;
            $sums[$type] = $row->amount === null ? $sums[$type] : $sums[$type]->plus((string) $row->amount);
        }

        return [
            'invoice_total' => (string) $sums[ArDocument::INVOICE]->toScale(2),
            'allocation_total' => (string) $sums[ArDocument::ALLOCATION]->toScale(2),
            'invoices' => $counts[ArDocument::INVOICE],
            'allocations' => $counts[ArDocument::ALLOCATION],
            'tax_invoices' => $counts[ArDocument::TAX_INVOICE],
        ];
    }

    /**
     * Every invoice of the snapshot with its aging, in invoice order.
     *
     * @return iterable<int, array{invoice: ArDocument, aged: AgedInvoice, disputed: bool}>
     */
    public static function aged(ArSnapshot $snapshot): iterable
    {
        $buckets = self::buckets();

        foreach (ArDocument::query()->where('ar_snapshot_id', $snapshot->id)->where('document_type', ArDocument::INVOICE)->orderBy('id')->lazyById(500)->chunk(500) as $invoices) {
            $allocated = [];

            foreach (ArDocument::query()
                ->where('ar_snapshot_id', $snapshot->id)
                ->where('document_type', ArDocument::ALLOCATION)
                ->where('document_date', '<=', $snapshot->as_of_date)
                ->whereIn('related_document', $invoices->pluck('document_number')->all())
                ->toBase()
                ->get(['related_document', 'amount']) as $allocation) {
                $allocated[(string) $allocation->related_document][] = (string) $allocation->amount;
            }

            foreach ($invoices as $invoice) {
                yield [
                    'invoice' => $invoice,
                    'aged' => AgingCalculator::age($buckets, $snapshot->as_of_date, $invoice->document_number, (string) $invoice->amount, $invoice->due_date, $allocated[$invoice->document_number] ?? []),
                    'disputed' => $invoice->status === ArDocument::DISPUTED,
                ];
            }
        }
    }

    /**
     * Bucket totals plus the exceptions, and the check that the buckets add
     * up to the outstanding of the snapshot.
     *
     * @return array{buckets: array<string, array{label: string, count: int, outstanding: string, disputed_count: int, disputed_outstanding: string}>, paid: int, over_allocated: array{count: int, credit: string}, outstanding_total: string, buckets_total: string, reconciles: bool}
     */
    public static function summary(ArSnapshot $snapshot): array
    {
        $labels = self::buckets()->labels();
        $zero = BigDecimal::zero();
        $counts = array_fill_keys(array_keys($labels), 0);
        $sums = array_fill_keys(array_keys($labels), $zero);
        $disputedCounts = array_fill_keys(array_keys($labels), 0);
        $disputedSums = array_fill_keys(array_keys($labels), $zero);

        $paid = 0;
        $overCount = 0;
        $credit = $zero;
        $invoiceTotal = $zero;
        $allocatedTotal = $zero;

        foreach (self::aged($snapshot) as $row) {
            $aged = $row['aged'];
            $invoiceTotal = $invoiceTotal->plus($aged->amount);
            $allocatedTotal = $allocatedTotal->plus($aged->allocated);

            if ($aged->state === AgedInvoice::PAID) {
                $paid++;

                continue;
            }

            if ($aged->state === AgedInvoice::OVER_ALLOCATED) {
                $overCount++;
                $credit = $credit->plus(BigDecimal::of($aged->outstanding)->negated());

                continue;
            }

            $bucket = (string) $aged->bucket;
            $counts[$bucket]++;
            $sums[$bucket] = $sums[$bucket]->plus($aged->outstanding);

            if ($row['disputed']) {
                $disputedCounts[$bucket]++;
                $disputedSums[$bucket] = $disputedSums[$bucket]->plus($aged->outstanding);
            }
        }

        $bucketsTotal = $zero;
        $buckets = [];

        foreach ($labels as $key => $label) {
            $bucketsTotal = $bucketsTotal->plus($sums[$key]);
            $buckets[$key] = [
                'label' => $label,
                'count' => $counts[$key],
                'outstanding' => (string) $sums[$key]->toScale(2),
                'disputed_count' => $disputedCounts[$key],
                'disputed_outstanding' => (string) $disputedSums[$key]->toScale(2),
            ];
        }

        // Outstanding of the snapshot = invoices − allocations up to the as-of date + credit balances set aside.
        $outstandingTotal = $invoiceTotal->minus($allocatedTotal)->plus($credit);

        return [
            'buckets' => $buckets,
            'paid' => $paid,
            'over_allocated' => ['count' => $overCount, 'credit' => (string) $credit->toScale(2)],
            'outstanding_total' => (string) $outstandingTotal->toScale(2),
            'buckets_total' => (string) $bucketsTotal->toScale(2),
            'reconciles' => $outstandingTotal->isEqualTo($bucketsTotal),
        ];
    }
}
