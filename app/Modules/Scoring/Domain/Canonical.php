<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain;

/**
 * Canonical JSON for fingerprints: map keys sorted at every level, list
 * order kept, no floats. Two equal inputs always produce the same bytes.
 */
final class Canonical
{
    /**
     * @param  array<mixed>  $data
     */
    public static function json(array $data): string
    {
        return json_encode(self::sort($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function hash(array $data): string
    {
        return hash('sha256', self::json($data));
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function sort(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sort($value);
            }
        }

        if (! array_is_list($data)) {
            ksort($data, SORT_STRING);
        }

        return $data;
    }
}
