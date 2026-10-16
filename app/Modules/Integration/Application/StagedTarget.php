<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application;

/**
 * What an adapter staged in the target module: the batch or snapshot, its
 * counts, and the totals that can be compared with the manifest's control
 * totals (null when the target cannot give one number, e.g. several units).
 * `created` is false when the module returned content it already held.
 */
final readonly class StagedTarget
{
    /**
     * @param  array<string, string|null>  $totals  control key => actual total
     */
    public function __construct(
        public string $type,
        public int $id,
        public string $publicId,
        public string $status,
        public int $rowCount,
        public int $rejectedCount,
        public array $totals,
        public bool $created,
    ) {}
}
