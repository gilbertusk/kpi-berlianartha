<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

use InvalidArgumentException;

final readonly class EffectivePeriod
{
    public function __construct(
        public string $effectiveFrom,
        public ?string $effectiveUntil = null,
    ) {
        self::assertDate($effectiveFrom, 'effectiveFrom');

        if ($effectiveUntil !== null) {
            self::assertDate($effectiveUntil, 'effectiveUntil');

            if ($effectiveUntil <= $effectiveFrom) {
                throw new InvalidArgumentException('effectiveUntil must be after effectiveFrom.');
            }
        }
    }

    public function contains(string $businessDate): bool
    {
        self::assertDate($businessDate, 'businessDate');

        return $businessDate >= $this->effectiveFrom
            && ($this->effectiveUntil === null || $businessDate < $this->effectiveUntil);
    }

    public function overlaps(self $other): bool
    {
        $thisUntil = $this->effectiveUntil ?? '9999-12-31';
        $otherUntil = $other->effectiveUntil ?? '9999-12-31';

        return $this->effectiveFrom < $otherUntil
            && $other->effectiveFrom < $thisUntil;
    }

    private static function assertDate(string $value, string $field): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$field} must use a valid YYYY-MM-DD business date.");
        }
    }
}
