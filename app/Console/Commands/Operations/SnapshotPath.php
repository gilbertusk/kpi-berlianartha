<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

/**
 * Object keys written to, and read back from, a local storage snapshot must
 * stay inside the snapshot: relative, no parent segments, no backslashes or
 * control characters, no drive letters.
 */
final class SnapshotPath
{
    public static function isSafe(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || preg_match('/[\x00-\x1F\x7F\\\\:]/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
