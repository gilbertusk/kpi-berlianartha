<?php

declare(strict_types=1);

namespace App\Modules\Target\Application;

use InvalidArgumentException;

/**
 * Reads and writes the target import file: UTF-8 CSV with a header row,
 * comma or semicolon separated (spreadsheets with an Indonesian locale save
 * semicolons).
 */
final class TargetCsv
{
    /** Columns the import needs; other columns are informational. */
    public const REQUIRED_COLUMNS = ['kpi_code', 'subject_type', 'subject_code', 'unit', 'target_value'];

    public const COLUMNS = ['kpi_code', 'kpi_name', 'subject_type', 'subject_code', 'subject_name', 'unit', 'target_value', 'source_note'];

    private const MAX_CELL_LENGTH = 255;

    /**
     * @return array{header: list<string>, rows: list<array{line: int, cells: array<string, string>}>}
     *
     * @throws InvalidArgumentException with a message that is safe to show
     */
    public static function parse(string $contents, int $maxRows): array
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new InvalidArgumentException('File harus berupa CSV dengan encoding UTF-8.');
        }

        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $firstLine = strtok($contents, "\n");
        $delimiter = $firstLine !== false && substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new InvalidArgumentException('File tidak dapat dibaca.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        $header = [];
        $rows = [];
        $line = 0;

        while (($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            $cells = array_map(static fn (?string $cell): string => trim((string) $cell), $record);

            if ($header === []) {
                $header = array_map(static fn (string $cell): string => mb_strtolower($cell), $cells);
                self::assertHeader($header);

                continue;
            }

            if (implode('', $cells) === '') {
                continue;
            }

            $named = [];

            foreach ($header as $position => $column) {
                if ($column !== '' && in_array($column, self::COLUMNS, true)) {
                    $named[$column] = mb_substr($cells[$position] ?? '', 0, self::MAX_CELL_LENGTH);
                }
            }

            // Stop before a file of many tiny rows can exhaust memory.
            if (count($rows) >= $maxRows) {
                fclose($stream);

                throw new InvalidArgumentException("File maksimal berisi {$maxRows} baris data.");
            }

            $rows[] = ['line' => $line, 'cells' => $named];
        }

        fclose($stream);

        if ($header === []) {
            throw new InvalidArgumentException('File tidak memiliki baris judul.');
        }

        return ['header' => $header, 'rows' => $rows];
    }

    /**
     * @param  list<string>  $header
     */
    private static function assertHeader(array $header): void
    {
        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $header));

        if ($missing !== []) {
            throw new InvalidArgumentException('Kolom wajib tidak ditemukan pada baris judul: '.implode(', ', $missing).'.');
        }

        if (count($header) !== count(array_unique($header))) {
            throw new InvalidArgumentException('Baris judul berisi nama kolom ganda.');
        }
    }

    /**
     * Neutralizes spreadsheet formulas in exported text (CSV injection).
     */
    public static function cell(?string $value): string
    {
        $value = (string) $value;
        $first = ltrim($value)[0] ?? '';

        if (! is_numeric($value) && in_array($first, ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}
