<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Modules\Accounting\Infrastructure\Persistence\Models\ArDocument;
use App\Modules\Reporting\Application\ReportContext;

/**
 * Tax invoice references of a snapshot with the status and reason the
 * source gave, and the invoices that have no tax invoice reference. No
 * issue deadline is applied: that rule has not been given by the business
 * owner.
 */
final class TaxInvoiceReport extends ArSnapshotReport
{
    public function key(): string
    {
        return 'tax_invoices';
    }

    public function title(): string
    {
        return 'Faktur pajak dan invoice tanpa faktur';
    }

    public function description(): string
    {
        return 'Faktur pajak per invoice dengan status dan alasan dari sistem sumber, serta invoice yang belum memiliki referensi faktur.';
    }

    public function columns(): array
    {
        return ['Tanggal as-of', 'Invoice', 'Kode customer', 'Tanggal invoice', 'Nomor faktur pajak', 'Tanggal faktur', 'Status sumber', 'Alasan status', 'Keterangan', 'Baris sumber'];
    }

    public function filters(): array
    {
        return ['snapshot'];
    }

    public function rows(ReportContext $context): iterable
    {
        $snapshot = $this->current($context);
        $tax = ArDocument::query()->where('ar_snapshot_id', $snapshot->id)->where('document_type', ArDocument::TAX_INVOICE)->orderBy('id')->get()->groupBy('related_document');

        foreach (ArDocument::query()->where('ar_snapshot_id', $snapshot->id)->where('document_type', ArDocument::INVOICE)->orderBy('id')->lazyById(500) as $invoice) {
            $references = $tax->get($invoice->document_number);

            if ($references === null) {
                yield [$snapshot->as_of_date, $invoice->document_number, $invoice->customer_code, $invoice->document_date, null, null, null, null, 'Belum ada referensi faktur pajak', (string) $invoice->source_line];

                continue;
            }

            foreach ($references as $reference) {
                yield [
                    $snapshot->as_of_date, $invoice->document_number, $invoice->customer_code, $invoice->document_date, $reference->document_number,
                    $reference->document_date, $reference->status, $reference->status_reason, null, (string) $reference->source_line,
                ];
            }
        }
    }
}
