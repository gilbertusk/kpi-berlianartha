<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

/**
 * Makes a value safe to put in a spreadsheet cell. Text that a spreadsheet
 * would read as a formula or a DDE command (it starts with =, +, -, @, a tab,
 * or a carriage return, also after leading spaces) is prefixed with an
 * apostrophe so it stays text. Plain decimal numbers, including negative
 * ones, are left as they are. Control characters and invalid UTF-8 are
 * removed, and a value longer than a spreadsheet cell can hold is cut.
 */
final class CellSanitizer
{
    /** Below Excel's limit of 32,767 characters per cell. */
    private const MAX_LENGTH = 32000;

    private const DECIMAL = '/^-?\d+(?:\.\d+)?$/';

    public static function clean(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_scrub($value, 'UTF-8'));

        if (mb_strlen($value) > self::MAX_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_LENGTH).'… [dipotong]';
        }

        if (preg_match(self::DECIMAL, $value) === 1) {
            return $value;
        }

        $first = ltrim($value, " \u{00A0}")[0] ?? '';
        $raw = $value[0] ?? '';

        if (in_array($first, ['=', '+', '-', '@'], true) || $raw === "\t" || $raw === "\r" || $raw === "\n") {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param  list<string|null>  $row
     * @return list<string>
     */
    public static function row(array $row): array
    {
        return array_map(self::clean(...), $row);
    }
}
