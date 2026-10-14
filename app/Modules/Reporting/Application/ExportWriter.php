<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use Closure;

/**
 * Writes a report to a local file in one format, row by row.
 */
interface ExportWriter
{
    /**
     * @param  list<string>  $columns
     * @param  iterable<int, list<string|null>>  $rows
     * @param  Closure(int): void  $progress  called with the number of rows written so far
     * @return int rows written
     *
     * @throws TooManyRows when the report exceeds $maxRows
     */
    public function write(string $path, ExportMetadata $metadata, array $columns, iterable $rows, Closure $progress, int $maxRows): int;
}
