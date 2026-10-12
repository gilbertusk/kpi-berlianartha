<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain;

/**
 * PRD §4.1: one active home team per date, plus recorded temporary
 * assignments that may overlap the home team.
 */
enum AssignmentType: string
{
    case Home = 'home';
    case Temporary = 'temporary';

    public function label(): string
    {
        return match ($this) {
            self::Home => 'Home team',
            self::Temporary => 'Penugasan sementara',
        };
    }
}
