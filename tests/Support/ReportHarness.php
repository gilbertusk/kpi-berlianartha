<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Reporting\Application\ReportCatalog;
use App\Modules\Reporting\Application\ReportFilters;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportExport;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Runs reports the way the page and the export worker do, and reads an
 * exported workbook back.
 */
final class ReportHarness
{
    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, string|null>> rows keyed by column heading
     */
    public static function rows(User $user, string $report, array $filters = []): array
    {
        [$definition, $context] = app(ReportCatalog::class)->authorize($user, $report, ReportFilters::fromArray($filters));
        $columns = $definition->columns();
        $rows = [];

        foreach ($definition->rows($context) as $row) {
            $rows[] = array_combine($columns, $row);
        }

        return $rows;
    }

    /**
     * @return array{info: array<string, string>, header: list<string>, rows: list<list<string>>}
     */
    public static function workbook(ReportExport $export): array
    {
        $local = storage_path('framework/testing/'.uniqid('export-', true).'.xlsx');
        @mkdir(dirname($local), 0777, true);
        file_put_contents($local, Storage::disk((string) $export->disk)->get((string) $export->path));

        $reader = new Reader;
        $reader->open($local);
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];

            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(static fn ($cell): string => (string) $cell, $row->toArray());
            }

            $sheets[$sheet->getName()] = $rows;
        }

        $reader->close();
        @unlink($local);

        $info = [];

        foreach ($sheets['Info'] ?? [] as $line) {
            $info[$line[0]] = $line[1] ?? '';
        }

        $data = $sheets['Data'] ?? [];

        return ['info' => $info, 'header' => $data[0] ?? [], 'rows' => array_slice($data, 1)];
    }
}
