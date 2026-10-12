<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain;

/**
 * PRD §4: Company → Group/Department → Team → Employee.
 */
enum UnitType: string
{
    case Company = 'company';
    case Department = 'department';
    case Team = 'team';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Company',
            self::Department => 'Group/Department',
            self::Team => 'Team',
        };
    }

    public function requiredParentType(): ?self
    {
        return match ($this) {
            self::Company => null,
            self::Department => self::Company,
            self::Team => self::Department,
        };
    }
}
