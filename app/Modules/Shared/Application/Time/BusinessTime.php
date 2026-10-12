<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Time;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final readonly class BusinessTime
{
    private DateTimeZone $businessTimezone;

    public function __construct(string $businessTimezone)
    {
        try {
            $this->businessTimezone = new DateTimeZone($businessTimezone);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Invalid business timezone.', previous: $exception);
        }
    }

    public function toBusiness(DateTimeInterface $timestamp): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($timestamp)
            ->setTimezone($this->businessTimezone);
    }

    public function businessDate(DateTimeInterface $timestamp): string
    {
        return $this->toBusiness($timestamp)->format('Y-m-d');
    }

    public function businessMidnightToUtc(string $businessDate): DateTimeImmutable
    {
        $local = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate, $this->businessTimezone);

        if ($local === false || $local->format('Y-m-d') !== $businessDate) {
            throw new InvalidArgumentException('Business date must use YYYY-MM-DD.');
        }

        return $local->setTimezone(new DateTimeZone('UTC'));
    }

    public function timezoneName(): string
    {
        return $this->businessTimezone->getName();
    }
}
