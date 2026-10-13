<?php

declare(strict_types=1);

namespace App\Modules\Target\Application;

use App\Modules\Target\Infrastructure\Persistence\Models\TargetImportRow;

/**
 * Validation result of one file row while a batch is being staged.
 */
final class StagedImportRow
{
    public string $status = TargetImportRow::STATUS_ERROR;

    /** @var list<string> */
    public array $errors = [];

    public ?int $targetId = null;

    /** Canonical four-decimal value when the row is valid. */
    public ?string $targetValue = null;

    public ?string $expectedState = null;

    /**
     * @param  array<string, string>  $raw
     */
    public function __construct(
        public readonly int $line,
        public readonly array $raw,
        public readonly ?string $unit,
        public readonly ?string $sourceNote,
    ) {}

    public function reject(string $message): void
    {
        $this->status = TargetImportRow::STATUS_ERROR;
        $this->errors[] = $message;
        $this->expectedState = null;
    }
}
