<?php

declare(strict_types=1);

namespace App\Modules\ActualEvidence\Domain;

use InvalidArgumentException;

/**
 * The dates an actual covers: inclusive business dates inside the period.
 * A daily KPI is entered per day. For other frequencies the window is
 * entered explicitly, because the business calendar (working days, shifts,
 * week and month boundaries) is not decided (DEC-015).
 */
final readonly class ObservationWindow
{
    private function __construct(
        public string $start,
        public string $end,
    ) {}

    /**
     * @throws InvalidArgumentException with a message that is safe to show
     */
    public static function for(?string $frequency, string $start, string $end, string $periodStart, string $periodEnd): self
    {
        if (! self::isDate($start) || ! self::isDate($end)) {
            throw new InvalidArgumentException('Tanggal observasi harus berformat YYYY-MM-DD dan merupakan tanggal yang valid.');
        }

        if ($end < $start) {
            throw new InvalidArgumentException('Tanggal akhir observasi tidak boleh sebelum tanggal mulai.');
        }

        if ($start < $periodStart || $end > $periodEnd) {
            throw new InvalidArgumentException("Rentang observasi harus berada di dalam periode ({$periodStart} sampai {$periodEnd}).");
        }

        if ($frequency === 'daily' && $start !== $end) {
            throw new InvalidArgumentException('KPI harian diisi per tanggal: tanggal mulai dan akhir observasi harus sama.');
        }

        return new self($start, $end);
    }

    public static function isDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    public function overlaps(string $start, string $end): bool
    {
        return $this->start <= $end && $start <= $this->end;
    }
}
