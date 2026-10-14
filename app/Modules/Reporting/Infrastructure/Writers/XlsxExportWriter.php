<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Writers;

use App\Modules\Reporting\Application\ExportMetadata;
use App\Modules\Reporting\Application\ExportWriter;
use App\Modules\Reporting\Application\TooManyRows;
use App\Modules\Reporting\Domain\CellSanitizer;
use Closure;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Streams rows into an .xlsx file: a sheet "Info" with the export metadata
 * and a sheet "Data". Rows are written as they arrive, so memory does not
 * grow with the size of the report. Every cell is text and is neutralized
 * against spreadsheet formulas; decimals keep their exact stored digits.
 */
final class XlsxExportWriter implements ExportWriter
{
    private const PROGRESS_EVERY = 500;

    public function write(string $path, ExportMetadata $metadata, array $columns, iterable $rows, Closure $progress, int $maxRows): int
    {
        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $writer->getCurrentSheet()->setName('Info');

            foreach ($metadata->lines() as [$label, $value]) {
                $writer->addRow(Row::fromValues(CellSanitizer::row([$label, $value])));
            }

            $writer->addNewSheetAndMakeItCurrent()->setName('Data');
            $writer->addRow(Row::fromValues(CellSanitizer::row($columns)));
            $written = 0;

            foreach ($rows as $row) {
                if ($written >= $maxRows) {
                    throw new TooManyRows;
                }

                $writer->addRow(Row::fromValues(CellSanitizer::row($row)));
                $written++;

                if ($written % self::PROGRESS_EVERY === 0) {
                    $progress($written);
                }
            }
        } finally {
            $writer->close();
        }

        $progress($written);

        return $written;
    }
}
