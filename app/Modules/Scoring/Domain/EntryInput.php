<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Domain;

/**
 * The newest version of one actual (one observation window) as the engine
 * sees it. Only a verified version is eligible; anything else means the
 * data is still in the workflow.
 */
final readonly class EntryInput
{
    public const STATUS_VERIFIED = 'verified';

    public const KIND_VALUE = 'value';

    public const KIND_NOT_APPLICABLE = 'not_applicable';

    /**
     * @param  string|null  $value  canonical decimal string; null for an N/A entry
     */
    public function __construct(
        public string $reference,
        public int $version,
        public string $windowStart,
        public string $windowEnd,
        public string $status,
        public string $kind,
        public ?string $value,
        public bool $isLate,
    ) {}

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function isValue(): bool
    {
        return $this->kind === self::KIND_VALUE;
    }

    /**
     * Sort key: observation windows of one target never overlap, so the
     * window orders entries completely; the reference breaks any tie.
     */
    public function sortKey(): string
    {
        return "{$this->windowStart}|{$this->windowEnd}|{$this->reference}";
    }

    /**
     * @return array{reference: string, version: int, window_start: string, window_end: string, status: string, kind: string, value: string|null, late: bool}
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'version' => $this->version,
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
            'status' => $this->status,
            'kind' => $this->kind,
            'value' => $this->value,
            'late' => $this->isLate,
        ];
    }
}
