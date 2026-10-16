<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application;

use RuntimeException;

/**
 * A private temporary file with an unguessable name, created exclusively so
 * no other process can have prepared it. The caller removes it.
 */
final class TempFile
{
    public static function create(string $prefix): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(16));
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }

        fclose($handle);
        @chmod($path, 0600);

        return $path;
    }
}
