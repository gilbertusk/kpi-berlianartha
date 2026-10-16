<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application;

use InvalidArgumentException;
use RuntimeException;

/**
 * Renames vendor column names in the header of a CSV file to the internal
 * contract names. Only the header changes; the data rows are copied as
 * they are, so the mapping is deterministic and a replay maps the same way.
 */
final class CsvHeaderMapper
{
    /**
     * @param  array<string, string>  $map  vendor column (lower case) => contract column
     */
    public static function map(string $source, string $target, array $map): void
    {
        $in = fopen($source, 'rb');
        $out = fopen($target, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('Cannot open the integration file.');
        }

        try {
            $header = fgets($in);

            if ($header === false) {
                throw new InvalidArgumentException('File tidak memiliki baris judul.');
            }

            $bom = str_starts_with($header, "\u{FEFF}") ? "\u{FEFF}" : '';
            $line = rtrim(substr($header, strlen($bom)), "\r\n");
            $delimiter = substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
            $columns = array_map(static function (?string $column) use ($map): string {
                $name = mb_strtolower(trim((string) $column));

                return $map[$name] ?? $name;
            }, str_getcsv($line, $delimiter, '"', ''));

            if (array_filter($columns, static fn (string $column): bool => preg_match('/^[a-z][a-z0-9_]{0,59}$/', $column) !== 1) !== []) {
                throw new InvalidArgumentException('Baris judul berisi nama kolom yang tidak dapat dipetakan ke kontrak.');
            }

            if (count(array_unique($columns)) !== count($columns)) {
                throw new InvalidArgumentException('Pemetaan kolom menghasilkan nama kolom ganda.');
            }

            fwrite($out, implode($delimiter, $columns)."\n");
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
    }
}
