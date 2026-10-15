<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

use InvalidArgumentException;

/**
 * Shared validation of record attributes against field specs.
 */
final class Fields
{
    private const DECIMAL = '/^\d{1,14}(?:\.\d{1,4})?$/';

    /**
     * @param  list<FieldSpec>  $specs
     * @param  array<string, mixed>  $input
     * @return array<string, string|int|null>
     */
    public static function normalize(array $specs, array $input): array
    {
        $known = array_map(static fn (FieldSpec $spec): string => $spec->name, $specs);

        foreach (array_keys($input) as $key) {
            if (! in_array($key, $known, true)) {
                throw new InvalidArgumentException('Isian tidak dikenal untuk jenis catatan ini.');
            }
        }

        $values = [];

        foreach ($specs as $spec) {
            $raw = $input[$spec->name] ?? null;
            $value = is_string($raw) ? trim(self::clean($raw, $spec->kind === FieldSpec::LONG_TEXT)) : $raw;

            if ($value === null || $value === '') {
                if ($spec->required) {
                    throw new InvalidArgumentException("{$spec->label} wajib diisi.");
                }

                $values[$spec->name] = null;

                continue;
            }

            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }

            if (! is_string($value)) {
                throw new InvalidArgumentException("{$spec->label} tidak valid.");
            }

            $values[$spec->name] = match ($spec->kind) {
                FieldSpec::INTEGER => self::integer($spec, $value),
                FieldSpec::DECIMAL => self::decimal($spec, $value),
                FieldSpec::CHOICE => self::choice($spec, $value),
                default => self::text($spec, $value),
            };
        }

        return $values;
    }

    /**
     * Removes invalid UTF-8 and control or format characters; only a long
     * text keeps line breaks and tabs.
     */
    private static function clean(string $value, bool $multiline): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $pattern = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u' : '/[\p{Cc}\p{Cf}]/u';

        return (string) preg_replace($pattern, '', $value);
    }

    private static function text(FieldSpec $spec, string $value): string
    {
        $max = $spec->kind === FieldSpec::LONG_TEXT ? max($spec->maxLength, 2000) : $spec->maxLength;

        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$spec->label} maksimal {$max} karakter.");
        }

        return $value;
    }

    private static function integer(FieldSpec $spec, string $value): int
    {
        if (preg_match('/^\d{1,6}$/', $value) !== 1 || (int) $value < 1) {
            throw new InvalidArgumentException("{$spec->label} harus bilangan bulat positif.");
        }

        return (int) $value;
    }

    private static function decimal(FieldSpec $spec, string $value): string
    {
        if (preg_match(self::DECIMAL, $value) !== 1) {
            throw new InvalidArgumentException("{$spec->label} harus angka desimal tidak negatif dengan titik (maks. 4 desimal).");
        }

        return $value;
    }

    private static function choice(FieldSpec $spec, string $value): string
    {
        if (! array_key_exists($value, $spec->options)) {
            throw new InvalidArgumentException("{$spec->label} tidak dikenal.");
        }

        return $value;
    }
}
