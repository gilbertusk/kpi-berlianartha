<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Modules\Accounting\Application\ArQuery;
use App\Modules\Accounting\Domain\AgedInvoice;
use App\Modules\Reporting\Application\ReportContext;

/**
 * Every invoice of a snapshot with outstanding amount, days past due,
 * bucket, dispute, collection owner, and next action.
 */
final class ArAgingReport extends ArSnapshotReport
{
    public function key(): string
    {
        return 'ar_aging';
    }

    public function title(): string
    {
        return 'Aging piutang per invoice';
    }

    public function description(): string
    {
        return 'Outstanding, umur, bucket, dispute, PIC penagihan, dan tindak lanjut per invoice dari snapshot sumber pada tanggal as-of.';
    }

    public function columns(): array
    {
        return [
            'Tanggal as-of', 'Versi bucket', 'Invoice', 'Kode customer', 'Nama customer', 'Tanggal invoice', 'Jatuh tempo', 'Mata uang', 'Nilai invoice',
            'Dialokasikan s.d. as-of', 'Outstanding', 'Hari lewat jatuh tempo', 'Bucket', 'Dispute', 'PIC penagihan', 'Tindak lanjut', 'Tanggal tindak lanjut', 'Baris sumber',
        ];
    }

    public function filters(): array
    {
        return ['snapshot', 'status'];
    }

    public function statuses(): array
    {
        return [...ArQuery::buckets()->labels(), 'disputed' => 'Dispute', AgedInvoice::OVER_ALLOCATED => 'Kelebihan alokasi', AgedInvoice::PAID => 'Lunas'];
    }

    public function rows(ReportContext $context): iterable
    {
        $snapshot = $this->current($context);
        $buckets = ArQuery::buckets();
        $labels = $buckets->labels();
        $filter = $context->filters->status;

        foreach (ArQuery::aged($snapshot) as $row) {
            $aged = $row['aged'];
            $invoice = $row['invoice'];

            $matches = match (true) {
                $filter === null => true,
                $filter === 'disputed' => $row['disputed'],
                $filter === AgedInvoice::PAID, $filter === AgedInvoice::OVER_ALLOCATED => $aged->state === $filter,
                default => $aged->bucket === $filter,
            };

            if (! $matches) {
                continue;
            }

            yield [
                $snapshot->as_of_date, $buckets->version, $invoice->document_number, $invoice->customer_code, $invoice->customer_name,
                $invoice->document_date, $invoice->due_date, $invoice->currency, $aged->amount, $aged->allocated, $aged->outstanding,
                (string) $aged->daysPastDue,
                match ($aged->state) {
                    AgedInvoice::PAID => 'Lunas',
                    AgedInvoice::OVER_ALLOCATED => 'Kelebihan alokasi (perlakuan belum diputuskan)',
                    default => $labels[(string) $aged->bucket],
                },
                $row['disputed'] ? 'ya' : 'tidak', $invoice->collection_owner, $invoice->next_action, $invoice->next_action_date, (string) $invoice->source_line,
            ];
        }
    }
}
