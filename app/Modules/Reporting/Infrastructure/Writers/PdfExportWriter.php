<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Writers;

use App\Modules\Reporting\Application\ExportMetadata;
use App\Modules\Reporting\Application\ExportWriter;
use App\Modules\Reporting\Application\TooManyRows;
use Closure;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * Renders a report as a landscape A4 PDF. A PDF is laid out in memory, so
 * it is meant for bounded reports: the caller passes a small row limit and
 * larger reports are exported as Excel. All text is HTML-escaped; remote
 * resources, scripts, and embedded PHP are disabled.
 */
final class PdfExportWriter implements ExportWriter
{
    public function write(string $path, ExportMetadata $metadata, array $columns, iterable $rows, Closure $progress, int $maxRows): int
    {
        $escape = static fn (mixed $value): string => htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<html><head><meta charset="utf-8"><style>'
            .'body{font-family:DejaVu Sans,sans-serif;font-size:7pt;color:#111}h1{font-size:12pt;margin:0 0 6pt}'
            .'table{border-collapse:collapse;width:100%}th,td{border:0.5pt solid #777;padding:2pt 3pt;text-align:left;vertical-align:top;word-wrap:break-word}'
            .'th{background:#eee}.meta td{border:none;padding:1pt 4pt 1pt 0}.meta{margin-bottom:8pt;width:auto}'
            .'</style></head><body>';
        $html .= '<h1>'.$escape($metadata->reportTitle).'</h1><table class="meta">';

        foreach ($metadata->lines() as [$label, $value]) {
            $html .= '<tr><td><strong>'.$escape($label).'</strong></td><td>'.$escape($value).'</td></tr>';
        }

        $html .= '</table><table><thead><tr>';

        foreach ($columns as $column) {
            $html .= '<th>'.$escape($column).'</th>';
        }

        $html .= '</tr></thead><tbody>';
        $written = 0;

        foreach ($rows as $row) {
            if ($written >= $maxRows) {
                throw new TooManyRows;
            }

            $html .= '<tr>';

            foreach ($row as $cell) {
                $html .= '<td>'.$escape($cell).'</td>';
            }

            $html .= '</tr>';
            $written++;
        }

        $html .= '</tbody></table></body></html>';
        $progress($written);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        if (file_put_contents($path, (string) $pdf->output()) === false) {
            throw new RuntimeException('The PDF could not be written.');
        }

        return $written;
    }
}
