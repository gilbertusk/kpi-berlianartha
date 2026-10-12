<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Validation;

use App\Modules\Shared\Domain\EffectivePeriod;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use InvalidArgumentException;

/**
 * Input guards for application services. They raise safe, user-facing
 * violations so a service stays correct even when called without a form
 * request (seeder, console, future API).
 */
trait GuardsInput
{
    private static function requireText(string $value, string $field, string $message, int $maxLength = 2000): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new BusinessRuleViolation("{$field}_required", $message);
        }

        if (mb_strlen($value) > $maxLength) {
            throw new BusinessRuleViolation("{$field}_too_long", "Isian {$field} melebihi {$maxLength} karakter.");
        }

        return $value;
    }

    private static function optionalText(?string $value, int $maxLength = 2000): ?string
    {
        $value = $value === null ? null : trim($value);

        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    /**
     * Changes to existing effective-dated records may only take effect from
     * the current business date on; earlier dates would rewrite history that
     * decisions were already based on. Retroactive corrections need their
     * own approved correction process.
     */
    private static function requireNotBefore(string $date, string $businessToday): void
    {
        if ($date < $businessToday) {
            throw new BusinessRuleViolation(
                'retroactive_change',
                'Tanggal berlaku tidak boleh sebelum hari ini. Koreksi histori memerlukan proses koreksi tersendiri.',
            );
        }
    }

    private static function requirePeriod(string $from, ?string $until = null): EffectivePeriod
    {
        try {
            return new EffectivePeriod($from, $until);
        } catch (InvalidArgumentException) {
            throw new BusinessRuleViolation(
                'invalid_period',
                'Tanggal berlaku tidak valid. Gunakan format YYYY-MM-DD dan tanggal akhir harus setelah tanggal mulai.',
            );
        }
    }
}
