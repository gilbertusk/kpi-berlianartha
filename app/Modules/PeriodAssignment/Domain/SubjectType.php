<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Domain;

/**
 * What a scorecard or a target belongs to: one employee, or an organization
 * unit (team, department, or company).
 */
enum SubjectType: string
{
    case Employee = 'employee';
    case Unit = 'unit';

    public function label(): string
    {
        return match ($this) {
            self::Employee => 'Pegawai',
            self::Unit => 'Unit',
        };
    }

    public function key(int $id): string
    {
        return "{$this->value}:{$id}";
    }

    /**
     * Subject of the KPI template that applies (KpiTemplate::SUBJECTS).
     */
    public function templateSubject(): string
    {
        return match ($this) {
            self::Employee => 'employee',
            self::Unit => 'team',
        };
    }
}
