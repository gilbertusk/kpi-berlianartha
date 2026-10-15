<?php

declare(strict_types=1);

namespace App\Modules\Production\Domain;

use InvalidArgumentException;

/**
 * One version of the production shift calendar, effective from a date
 * (DEC-015). Nothing about it is assumed: the shifts, and the operational
 * date of a shift that runs past midnight (the date it starts or the date it
 * ends), are part of the approved configuration.
 */
final readonly class ShiftCalendar
{
    public const ANCHOR_START = 'start';

    public const ANCHOR_END = 'end';

    /**
     * @param  list<Shift>  $shifts
     */
    private function __construct(
        public string $version,
        public string $effectiveFrom,
        public string $label,
        public string $overnightAnchor,
        public array $shifts,
    ) {}

    /**
     * @param  array<mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $version = $config['version'] ?? null;
        $effectiveFrom = $config['effective_from'] ?? null;
        $label = $config['label'] ?? null;
        $anchor = $config['overnight_operational_date'] ?? null;
        $shifts = $config['shifts'] ?? null;

        if (! is_string($version) || preg_match('/^[a-z0-9][a-z0-9._-]{1,39}$/', $version) !== 1) {
            throw new InvalidArgumentException('Versi kalender shift tidak valid.');
        }

        if (! is_string($effectiveFrom) || ! self::isDate($effectiveFrom)) {
            throw new InvalidArgumentException("Kalender shift {$version}: effective_from harus YYYY-MM-DD.");
        }

        if (! is_string($label) || trim($label) === '') {
            throw new InvalidArgumentException("Kalender shift {$version}: label wajib diisi.");
        }

        if ($anchor !== self::ANCHOR_START && $anchor !== self::ANCHOR_END) {
            throw new InvalidArgumentException("Kalender shift {$version}: overnight_operational_date harus 'start' atau 'end'; tidak ada nilai bawaan.");
        }

        if (! is_array($shifts) || $shifts === [] || ! array_is_list($shifts)) {
            throw new InvalidArgumentException("Kalender shift {$version}: daftar shift wajib diisi.");
        }

        $parsed = [];
        $taken = array_fill(0, 1440, null);

        foreach ($shifts as $shift) {
            $parsed[] = $current = self::shift($version, $shift);

            foreach (self::minutes($current) as $minute) {
                if ($taken[$minute] !== null) {
                    throw new InvalidArgumentException("Kalender shift {$version}: shift {$current->code} tumpang tindih dengan {$taken[$minute]}.");
                }

                $taken[$minute] = $current->code;
            }
        }

        $codes = array_map(static fn (Shift $shift): string => $shift->code, $parsed);

        if (count(array_unique($codes)) !== count($codes)) {
            throw new InvalidArgumentException("Kalender shift {$version}: kode shift harus unik.");
        }

        return new self($version, $effectiveFrom, $label, $anchor, $parsed);
    }

    /**
     * The operational date of a shift that starts on a date.
     */
    public function operationalDate(Shift $shift, string $startDate): string
    {
        return $shift->crossesMidnight() && $this->overnightAnchor === self::ANCHOR_END
            ? self::addDays($startDate, 1)
            : $startDate;
    }

    public static function addDays(string $date, int $days): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));

        if ($parsed === false) {
            throw new InvalidArgumentException('Tanggal harus YYYY-MM-DD.');
        }

        return $parsed->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    public static function isDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    private static function shift(string $version, mixed $config): Shift
    {
        if (! is_array($config)) {
            throw new InvalidArgumentException("Kalender shift {$version}: setiap shift harus berisi code, start, dan end.");
        }

        $code = $config['code'] ?? null;

        if (! is_string($code) || preg_match('/^[A-Z0-9][A-Z0-9_-]{0,19}$/', $code) !== 1) {
            throw new InvalidArgumentException("Kalender shift {$version}: kode shift harus huruf besar atau angka.");
        }

        $start = self::minute($config['start'] ?? null);
        $end = self::minute($config['end'] ?? null);

        if ($start === null || $end === null || $start === $end) {
            throw new InvalidArgumentException("Kalender shift {$version}: shift {$code} membutuhkan jam mulai dan selesai HH:MM yang berbeda.");
        }

        return new Shift($code, $start, $end);
    }

    private static function minute(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 60 + (int) $match[2];
    }

    /**
     * @return list<int> minutes of the day the shift covers
     */
    private static function minutes(Shift $shift): array
    {
        return $shift->crossesMidnight()
            ? [...range($shift->startMinute, 1439), ...($shift->endMinute > 0 ? range(0, $shift->endMinute - 1) : [])]
            : range($shift->startMinute, $shift->endMinute - 1);
    }
}
