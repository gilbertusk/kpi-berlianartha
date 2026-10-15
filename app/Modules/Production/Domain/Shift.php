<?php

declare(strict_types=1);

namespace App\Modules\Production\Domain;

/**
 * One shift of a calendar, as minutes after local midnight. A shift whose end
 * is before its start runs past midnight: [start, 24:00) on its start date
 * and [00:00, end) on the next date.
 */
final readonly class Shift
{
    public function __construct(
        public string $code,
        public int $startMinute,
        public int $endMinute,
    ) {}

    public function crossesMidnight(): bool
    {
        return $this->endMinute < $this->startMinute;
    }

    /**
     * Whether a minute of the shift's start date lies in the shift.
     */
    public function coversHead(int $minute): bool
    {
        return $this->crossesMidnight()
            ? $minute >= $this->startMinute
            : $minute >= $this->startMinute && $minute < $this->endMinute;
    }

    /**
     * Whether a minute of the day after the start date lies in the shift.
     */
    public function coversTail(int $minute): bool
    {
        return $this->crossesMidnight() && $minute < $this->endMinute;
    }

    public function window(): string
    {
        return sprintf('%02d:%02d–%02d:%02d', intdiv($this->startMinute, 60), $this->startMinute % 60, intdiv($this->endMinute, 60), $this->endMinute % 60);
    }
}
