<?php

declare(strict_types=1);

namespace App\Modules\Production\Domain;

use InvalidArgumentException;

/**
 * The effective-dated versions of the shift calendar. A local moment belongs
 * to the shift of the calendar in force on the date the shift starts, so a
 * night shift that started under one version keeps it after midnight.
 */
final readonly class ShiftCalendars
{
    /**
     * @param  list<ShiftCalendar>  $calendars  ascending by effective date
     */
    private function __construct(private array $calendars) {}

    /**
     * @param  array<mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        if (! array_is_list($config)) {
            throw new InvalidArgumentException('Kalender shift harus berupa daftar versi.');
        }

        $calendars = array_map(static fn (mixed $calendar): ShiftCalendar => ShiftCalendar::fromArray(is_array($calendar) ? $calendar : []), $config);
        usort($calendars, static fn (ShiftCalendar $a, ShiftCalendar $b): int => strcmp($a->effectiveFrom, $b->effectiveFrom));
        $dates = array_map(static fn (ShiftCalendar $calendar): string => $calendar->effectiveFrom, $calendars);
        $versions = array_map(static fn (ShiftCalendar $calendar): string => $calendar->version, $calendars);

        if (count(array_unique($dates)) !== count($dates) || count(array_unique($versions)) !== count($versions)) {
            throw new InvalidArgumentException('Setiap versi kalender shift membutuhkan versi dan tanggal berlaku yang unik.');
        }

        return new self($calendars);
    }

    public function isEmpty(): bool
    {
        return $this->calendars === [];
    }

    public function forDate(string $date): ?ShiftCalendar
    {
        $found = null;

        foreach ($this->calendars as $calendar) {
            if ($calendar->effectiveFrom <= $date) {
                $found = $calendar;
            }
        }

        return $found;
    }

    /**
     * The shift of a local date and minute of day. Null when the moment is
     * outside every shift; an exception when two calendar versions claim it.
     */
    public function resolve(string $localDate, int $minute): ?ShiftMatch
    {
        $matches = [];
        $previousDate = ShiftCalendar::addDays($localDate, -1);

        $current = $this->forDate($localDate);

        foreach ($current === null ? [] : $current->shifts as $shift) {
            if ($shift->coversHead($minute)) {
                $matches[] = new ShiftMatch($shift->code, $current->operationalDate($shift, $localDate), $current->version);
            }
        }

        $previous = $this->forDate($previousDate);

        foreach ($previous === null ? [] : $previous->shifts as $shift) {
            if ($shift->coversTail($minute)) {
                $matches[] = new ShiftMatch($shift->code, $previous->operationalDate($shift, $previousDate), $previous->version);
            }
        }

        if (count($matches) > 1) {
            throw new InvalidArgumentException('Waktu ini termasuk dua shift dari versi kalender yang berbeda.');
        }

        return $matches[0] ?? null;
    }
}
