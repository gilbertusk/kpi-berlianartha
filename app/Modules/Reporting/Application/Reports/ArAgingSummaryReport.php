<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Reports;

use App\Modules\Accounting\Application\ArQuery;
use App\Modules\Reporting\Application\ReportContext;

/**
 * Bucket totals of a snapshot and its reconciliation: the source totals,
 * the control totals given at import, and the check that the buckets add up
 * to the outstanding.
 */
final class ArAgingSummaryReport extends ArSnapshotReport
{
    public function key(): string
    {
        return 'ar_aging_summary';
    }

    public function title(): string
    {
        return 'Ringkasan aging dan rekonsiliasi piutang';
    }

    public function description(): string
    {
        return 'Jumlah dan nilai outstanding per bucket (termasuk yang dispute), pengecualian, dan rekonsiliasi terhadap total sumber dan total kontrol.';
    }

    public function columns(): array
    {
        return ['Bagian', 'Keterangan', 'Jumlah dokumen', 'Nilai', 'Di antaranya dispute (jumlah)', 'Di antaranya dispute (nilai)'];
    }

    public function filters(): array
    {
        return ['snapshot'];
    }

    public function rows(ReportContext $context): iterable
    {
        $snapshot = $this->current($context);
        $summary = ArQuery::summary($snapshot);
        $totals = ArQuery::totals($snapshot->id);

        foreach ($summary['buckets'] as $bucket) {
            yield ['Bucket', $bucket['label'], (string) $bucket['count'], $bucket['outstanding'], (string) $bucket['disputed_count'], $bucket['disputed_outstanding']];
        }

        yield ['Pengecualian', 'Lunas pada tanggal as-of', (string) $summary['paid'], '0.00', null, null];
        yield ['Pengecualian', 'Kelebihan alokasi (saldo kredit, perlakuan belum diputuskan)', (string) $summary['over_allocated']['count'], $summary['over_allocated']['credit'], null, null];
        yield ['Rekonsiliasi', 'Total invoice pada snapshot', (string) $totals['invoices'], $totals['invoice_total'], null, null];
        yield ['Rekonsiliasi', 'Total kontrol invoice dari sumber', null, $snapshot->control_invoice_total ?? 'tidak diberikan', null, null];
        yield ['Rekonsiliasi', 'Total alokasi pada snapshot (semua tanggal)', (string) $totals['allocations'], $totals['allocation_total'], null, null];
        yield ['Rekonsiliasi', 'Total kontrol alokasi dari sumber', null, $snapshot->control_allocation_total ?? 'tidak diberikan', null, null];
        yield ['Rekonsiliasi', 'Outstanding per tanggal as-of (di luar saldo kredit)', null, $summary['outstanding_total'], null, null];
        yield ['Rekonsiliasi', 'Jumlah seluruh bucket', null, $summary['buckets_total'], null, null];
        yield ['Rekonsiliasi', 'Bucket = outstanding', null, $summary['reconciles'] ? 'cocok' : 'TIDAK COCOK', null, null];
    }
}
