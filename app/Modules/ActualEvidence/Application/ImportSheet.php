<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Application;

use DateTimeInterface;
use InvalidArgumentException;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;
use ZipArchive;

/**
 * Reads the actual import file: UTF-8 CSV (comma or semicolon) or the first
 * sheet of an XLSX workbook, with a header row. Rows are counted while
 * reading, so an oversized file is refused before it is held in memory.
 */
final class ImportSheet
{
    public const REQUIRED_COLUMNS = ['kpi_code', 'subject_type', 'subject_code', 'window_start', 'window_end', 'unit', 'actual_value'];

    public const COLUMNS = ['kpi_code', 'kpi_name', 'subject_type', 'subject_code', 'subject_name', 'window_start', 'window_end', 'unit', 'actual_value', 'source_system', 'source_reference', 'note'];

    public const FORMATS = ['csv', 'xlsx'];

    private const MAX_CELL_LENGTH = 255;

    private const MAX_COLUMNS = 50;

    /** An XLSX is a ZIP; these bound what a small upload may expand to. */
    private const MAX_ARCHIVE_ENTRIES = 100;

    private const MAX_UNCOMPRESSED_BYTES = 20 * 1024 * 1024;

    /**
     * @return list<array{line: int, cells: array<string, string>}>
     *
     * @throws InvalidArgumentException with a message that is safe to show
     */
    public static function read(string $path, string $format, int $maxRows): array
    {
        $header = [];
        $rows = [];
        $line = 0;

        foreach (self::records($path, $format) as $record) {
            $line++;

            // Blank lines are skipped below but still cost time to read.
            if ($line > $maxRows * 2 + 1) {
                throw new InvalidArgumentException("File maksimal berisi {$maxRows} baris data.");
            }

            $cells = array_map(static fn (mixed $cell): string => self::text($cell), array_slice(array_values($record), 0, self::MAX_COLUMNS));

            if ($header === []) {
                $header = array_map(static fn (string $cell): string => mb_strtolower($cell), $cells);
                self::assertHeader($header);

                continue;
            }

            if (implode('', $cells) === '') {
                continue;
            }

            if (count($rows) >= $maxRows) {
                throw new InvalidArgumentException("File maksimal berisi {$maxRows} baris data.");
            }

            $named = [];

            foreach ($header as $position => $column) {
                if (in_array($column, self::COLUMNS, true)) {
                    $named[$column] = mb_substr($cells[$position] ?? '', 0, self::MAX_CELL_LENGTH);
                }
            }

            $rows[] = ['line' => $line, 'cells' => $named];
        }

        if ($header === []) {
            throw new InvalidArgumentException('File tidak memiliki baris judul.');
        }

        return $rows;
    }

    /**
     * @return iterable<array<array-key, mixed>>
     */
    private static function records(string $path, string $format): iterable
    {
        if ($format === 'xlsx') {
            yield from self::xlsxRecords($path);

            return;
        }

        $contents = (string) file_get_contents($path);

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new InvalidArgumentException('File CSV harus memakai encoding UTF-8.');
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

        try {
            while (($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                yield $record;
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return iterable<array<array-key, mixed>>
     */
    private static function xlsxRecords(string $path): iterable
    {
        self::assertReasonableArchive($path);
        $reader = new XlsxReader;

        try {
            $reader->open($path);
        } catch (Throwable) {
            throw new InvalidArgumentException('File XLSX tidak dapat dibaca.');
        }

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield $row->toArray();
                }

                break; // first sheet only
            }
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InvalidArgumentException('File XLSX rusak atau tidak dapat dibaca.');
        } finally {
            $reader->close();
        }
    }

    private static function assertReasonableArchive(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('File XLSX tidak dapat dibaca.');
        }

        $total = 0;
        $entries = $zip->numFiles;

        for ($index = 0; $index < min($entries, self::MAX_ARCHIVE_ENTRIES); $index++) {
            $total += (int) ($zip->statIndex($index)['size'] ?? 0);
        }

        $zip->close();

        if ($entries > self::MAX_ARCHIVE_ENTRIES || $total > self::MAX_UNCOMPRESSED_BYTES) {
            throw new InvalidArgumentException('File XLSX terlalu besar setelah diekstrak.');
        }
    }

    /**
     * Spreadsheet cells arrive typed. Dates become YYYY-MM-DD; numbers keep
     * the digits the sheet stored. Text cells are the safest way to send
     * values with decimals.
     */
    private static function text(mixed $cell): string
    {
        return match (true) {
            $cell instanceof DateTimeInterface => $cell->format('Y-m-d'),
            is_int($cell) => (string) $cell,
            is_float($cell) => self::floatText($cell),
            is_bool($cell) => $cell ? '1' : '0',
            is_string($cell) => trim($cell),
            default => '',
        };
    }

    /**
     * A double holds about 15 significant digits; printing more shows binary
     * noise (1234567.8912 as 1234567.8911999999) and would fail the decimal
     * check for a value the sheet shows correctly.
     */
    private static function floatText(float $cell): string
    {
        $wholeDigits = strlen((string) (int) floor(abs($cell)));
        $decimals = max(0, min(10, 15 - $wholeDigits));
        $text = number_format($cell, $decimals, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
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

        $named = array_values(array_filter($header, static fn (string $column): bool => $column !== ''));

        if (count($named) !== count(array_unique($named))) {
            throw new InvalidArgumentException('Baris judul berisi nama kolom ganda.');
        }
    }
}
