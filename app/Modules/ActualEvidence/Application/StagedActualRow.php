<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Application;

use App\Modules\ActualEvidence\Domain\EntryKind;
use App\Modules\ActualEvidence\Domain\ObservationWindow;
use App\Modules\ActualEvidence\Infrastructure\Persistence\Models\ActualImportRow;

/**
 * Validation result of one file row while an actual import is being staged.
 */
final class StagedActualRow
{
    public string $status = ActualImportRow::STATUS_ERROR;

    /** @var list<string> */
    public array $errors = [];

    public ?int $targetId = null;

    public ?ObservationWindow $window = null;

    public ?EntryKind $kind = null;

    /** Canonical four-decimal value when the row carries one. */
    public ?string $value = null;

    public ?string $unit = null;

    public ?string $note = null;

    public ?string $sourceSystem = null;

    public ?string $sourceReference = null;

    public ?string $expectedState = null;

    /**
     * @param  array<string, string>  $raw
     */
    public function __construct(
        public readonly int $line,
        public readonly array $raw,
    ) {}

    public function reject(string $message): void
    {
        $this->status = ActualImportRow::STATUS_ERROR;
        $this->errors[] = $message;
        $this->expectedState = null;
    }
}
