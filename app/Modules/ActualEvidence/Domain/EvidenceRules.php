<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Domain;

use InvalidArgumentException;
use ZipArchive;

/**
 * Technical acceptance rules for an evidence file. The extension, the MIME
 * type detected from the content, and the leading bytes must all agree; the
 * type reported by the browser is never used.
 *
 * The allowlist and limits are conservative technical defaults. The business
 * evidence policy (types, size, retention, classification per KPI) is not
 * decided (DEC-006, DEC-010).
 */
final readonly class EvidenceRules
{
    /** extension => [allowed detected MIME types, leading byte signatures] */
    private const TYPES = [
        'pdf' => [['application/pdf'], ['%PDF-']],
        'png' => [['image/png'], ["\x89PNG\r\n\x1a\n"]],
        'jpg' => [['image/jpeg'], ["\xFF\xD8\xFF"]],
        'jpeg' => [['image/jpeg'], ["\xFF\xD8\xFF"]],
        'xlsx' => [['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], ["PK\x03\x04"]],
    ];

    public function __construct(
        public int $maxBytes,
        public int $maxFilesPerActual,
    ) {}

    /**
     * @return list<string>
     */
    public static function extensions(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * A display-safe file name: no path, no control characters, bounded
     * length. It is metadata only and never becomes a storage key.
     */
    public static function safeName(string $clientName): string
    {
        $name = basename(str_replace('\\', '/', $clientName));
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '', $name) ?? '';
        $name = trim($name, " .\t");

        return $name === '' ? 'bukti' : mb_substr($name, -200);
    }

    /**
     * @return string the extension the file was accepted as
     *
     * @throws InvalidArgumentException with a message that is safe to show
     */
    public function accept(string $clientName, int $byteSize, string $detectedMime, string $leadingBytes, int $existingFiles): string
    {
        if ($existingFiles >= $this->maxFilesPerActual) {
            throw new InvalidArgumentException("Satu aktual maksimal memiliki {$this->maxFilesPerActual} file bukti.");
        }

        if ($byteSize <= 0) {
            throw new InvalidArgumentException('File bukti kosong.');
        }

        if ($byteSize > $this->maxBytes) {
            throw new InvalidArgumentException('Ukuran file bukti melebihi '.self::megabytes($this->maxBytes).' MB.');
        }

        $name = self::safeName($clientName);
        $parts = explode('.', mb_strtolower($name));
        $extension = count($parts) > 1 ? (string) end($parts) : '';

        if (! isset(self::TYPES[$extension])) {
            throw new InvalidArgumentException('Jenis file tidak diizinkan. Gunakan: '.implode(', ', self::extensions()).'.');
        }

        // "laporan.pdf.exe" is caught above; "laporan.exe.pdf" is caught by
        // the content checks below, which do not look at the name at all.
        [$mimes, $signatures] = self::TYPES[$extension];

        $signatureMatches = false;

        foreach ($signatures as $signature) {
            $signatureMatches = $signatureMatches || str_starts_with($leadingBytes, $signature);
        }

        if (! $signatureMatches || ! in_array($detectedMime, $mimes, true)) {
            throw new InvalidArgumentException('Isi file tidak sesuai dengan jenis file pada namanya.');
        }

        return $extension;
    }

    /**
     * An .xlsx must really be a spreadsheet: any other ZIP (archive, JAR,
     * macro-enabled document) starts with the same bytes.
     *
     * @throws InvalidArgumentException with a message that is safe to show
     */
    public static function assertWorkbook(string $contents): void
    {
        // tmpfile() is removed by the system when the handle closes.
        $handle = tmpfile();

        if ($handle === false) {
            throw new InvalidArgumentException('File bukti tidak dapat diperiksa.');
        }

        try {
            fwrite($handle, $contents);
            fflush($handle);
            $path = stream_get_meta_data($handle)['uri'] ?? null;
            $zip = new ZipArchive;

            if (! is_string($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
                throw new InvalidArgumentException('Isi file tidak sesuai dengan jenis file pada namanya.');
            }

            $isWorkbook = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('xl/workbook.xml') !== false;
            $hasMacros = $zip->locateName('xl/vbaProject.bin') !== false;
            $zip->close();

            if (! $isWorkbook || $hasMacros) {
                throw new InvalidArgumentException($hasMacros ? 'Workbook dengan macro tidak diizinkan sebagai bukti.' : 'Isi file tidak sesuai dengan jenis file pada namanya.');
            }
        } finally {
            fclose($handle);
        }
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1_048_576, 1, '.', ''), '0'), '.');
    }
}
