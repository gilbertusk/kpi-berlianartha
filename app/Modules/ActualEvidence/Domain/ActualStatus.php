<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Domain;

/**
 * Lifecycle of one actual version (PRD §6.3, prompt Step 08):
 * DRAFT → SUBMITTED → VERIFIED, or SUBMITTED → REJECTED → DRAFT when the
 * verifier returns it. MISSING is not a status: it is the absence of any
 * entry for a target, and is never the same as a value of zero.
 *
 * A verified actual is final here; later stages (review, calibration,
 * correction) belong to the workflow step.
 */
enum ActualStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Menunggu verifikasi',
            self::Verified => 'Terverifikasi',
            self::Rejected => 'Ditolak',
        };
    }

    /**
     * The owner may still change the entry and its evidence.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::Submitted->value],
            self::Submitted->value => [self::Verified->value, self::Rejected->value],
            self::Rejected->value => [self::Draft->value],
            self::Verified->value => [],
        ];
    }
}
