<?php

declare(strict_types=1);

use App\Modules\Production\Domain\ShiftCalendar;
use App\Modules\Production\Domain\ShiftCalendars;

/*
 * The shift calendar is not approved yet (DEC-015). These calendars are
 * NON-PRODUCTION FIXTURES that exercise the rules any approved calendar
 * follows: shift boundaries, overnight shifts, and version changes.
 */

/**
 * @return array<string, mixed>
 */
function fixtureCalendar(string $anchor = 'start', string $from = '2026-01-01', string $version = 'fixture-3x8'): array
{
    return [
        'version' => $version,
        'effective_from' => $from,
        'label' => 'NON-PRODUCTION FIXTURE',
        'overnight_operational_date' => $anchor,
        'shifts' => [
            ['code' => 'S1', 'start' => '07:00', 'end' => '15:00'],
            ['code' => 'S2', 'start' => '15:00', 'end' => '23:00'],
            ['code' => 'S3', 'start' => '23:00', 'end' => '07:00'],
        ],
    ];
}

function shiftAt(ShiftCalendars $calendars, string $moment): ?array
{
    [$date, $time] = explode(' ', $moment);
    [$hour, $minute] = array_map(intval(...), explode(':', $time));
    $match = $calendars->resolve($date, $hour * 60 + $minute);

    return $match === null ? null : [$match->shiftCode, $match->operationalDate];
}

test('every minute falls into the right shift at the shift boundaries', function (string $moment, string $shift, string $date): void {
    expect(shiftAt(ShiftCalendars::fromArray([fixtureCalendar()]), $moment))->toBe([$shift, $date]);
})->with([
    'first minute of S1' => ['2026-10-13 07:00', 'S1', '2026-10-13'],
    'last minute of S1' => ['2026-10-13 14:59', 'S1', '2026-10-13'],
    'first minute of S2' => ['2026-10-13 15:00', 'S2', '2026-10-13'],
    'last minute of S2' => ['2026-10-13 22:59', 'S2', '2026-10-13'],
    'start of the night shift' => ['2026-10-13 23:00', 'S3', '2026-10-13'],
    'midnight' => ['2026-10-14 00:00', 'S3', '2026-10-13'],
    'last minute of the night shift' => ['2026-10-14 06:59', 'S3', '2026-10-13'],
    'across a month end' => ['2026-11-01 02:00', 'S3', '2026-10-31'],
    'across a year end' => ['2027-01-01 03:00', 'S3', '2026-12-31'],
]);

test('an overnight shift counts on the date it ends when the calendar says so', function (): void {
    $calendars = ShiftCalendars::fromArray([fixtureCalendar('end')]);

    expect(shiftAt($calendars, '2026-10-13 23:30'))->toBe(['S3', '2026-10-14'])
        ->and(shiftAt($calendars, '2026-10-14 06:59'))->toBe(['S3', '2026-10-14'])
        ->and(shiftAt($calendars, '2026-10-14 07:00'))->toBe(['S1', '2026-10-14']);
});

test('a moment outside every shift or before the first calendar has no shift', function (): void {
    $dayOnly = ShiftCalendars::fromArray([[...fixtureCalendar(), 'shifts' => [['code' => 'S1', 'start' => '07:00', 'end' => '15:00']]]]);

    expect(shiftAt($dayOnly, '2026-10-13 16:00'))->toBeNull()
        ->and(shiftAt($dayOnly, '2026-10-13 06:59'))->toBeNull()
        ->and(shiftAt(ShiftCalendars::fromArray([fixtureCalendar(from: '2026-10-14')]), '2026-10-13 10:00'))->toBeNull()
        ->and(ShiftCalendars::fromArray([])->isEmpty())->toBeTrue();
});

test('a night shift keeps the calendar version it started under; a moment claimed by two versions is refused', function (): void {
    $twelveHours = [
        'version' => 'fixture-2x12',
        'effective_from' => '2026-10-14',
        'label' => 'NON-PRODUCTION FIXTURE',
        'overnight_operational_date' => 'end',
        'shifts' => [['code' => 'D', 'start' => '08:00', 'end' => '20:00'], ['code' => 'N', 'start' => '20:00', 'end' => '08:00']],
    ];
    $calendars = ShiftCalendars::fromArray([$twelveHours, fixtureCalendar()]);

    expect(shiftAt($calendars, '2026-10-14 03:00'))->toBe(['S3', '2026-10-13'])
        ->and($calendars->resolve('2026-10-14', 3 * 60)?->calendarVersion)->toBe('fixture-3x8')
        ->and(shiftAt($calendars, '2026-10-14 21:00'))->toBe(['N', '2026-10-15'])
        ->and(shiftAt($calendars, '2026-10-15 07:59'))->toBe(['N', '2026-10-15']);

    $overlapping = ShiftCalendars::fromArray([[...$twelveHours, 'shifts' => [['code' => 'D', 'start' => '06:00', 'end' => '18:00'], ['code' => 'N', 'start' => '18:00', 'end' => '06:00']]], fixtureCalendar()]);

    expect(fn () => shiftAt($overlapping, '2026-10-14 06:30'))->toThrow(InvalidArgumentException::class, 'dua shift');
});

test('a calendar is refused unless it is complete and unambiguous', function (array $calendar, string $message): void {
    expect(fn () => ShiftCalendar::fromArray($calendar))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no overnight rule' => [[...fixtureCalendar(), 'overnight_operational_date' => null], 'tidak ada nilai bawaan'],
    'no version' => [[...fixtureCalendar(), 'version' => ''], 'Versi'],
    'bad effective date' => [[...fixtureCalendar(), 'effective_from' => '2026-02-30'], 'effective_from'],
    'no label' => [[...fixtureCalendar(), 'label' => ' '], 'label'],
    'no shifts' => [[...fixtureCalendar(), 'shifts' => []], 'daftar shift'],
    'overlap' => [[...fixtureCalendar(), 'shifts' => [['code' => 'A', 'start' => '07:00', 'end' => '16:00'], ['code' => 'B', 'start' => '15:00', 'end' => '23:00']]], 'tumpang tindih'],
    'duplicate code' => [[...fixtureCalendar(), 'shifts' => [['code' => 'A', 'start' => '07:00', 'end' => '15:00'], ['code' => 'A', 'start' => '15:00', 'end' => '23:00']]], 'unik'],
    'empty shift' => [[...fixtureCalendar(), 'shifts' => [['code' => 'A', 'start' => '07:00', 'end' => '07:00']]], 'berbeda'],
    'hour 24' => [[...fixtureCalendar(), 'shifts' => [['code' => 'A', 'start' => '16:00', 'end' => '24:00']]], 'berbeda'],
    'lower-case code' => [[...fixtureCalendar(), 'shifts' => [['code' => 'a', 'start' => '07:00', 'end' => '15:00']]], 'kode shift'],
]);

test('versions need unique dates and names', function (): void {
    expect(fn () => ShiftCalendars::fromArray([fixtureCalendar(), fixtureCalendar(version: 'fixture-other')]))->toThrow(InvalidArgumentException::class, 'unik')
        ->and(fn () => ShiftCalendars::fromArray([fixtureCalendar(), fixtureCalendar(from: '2026-06-01')]))->toThrow(InvalidArgumentException::class, 'unik');
});
