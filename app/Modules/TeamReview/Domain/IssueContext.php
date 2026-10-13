<?php

declare(strict_types=1);

namespace App\Modules\TeamReview\Domain;

/**
 * What a problem is about, besides the KPIs it affects (PRD §9.2). The
 * reference is free text: the master data for work orders, machines,
 * shifts, and customers has no approved owner yet (DEC-016).
 */
enum IssueContext: string
{
    case WorkOrder = 'work_order';
    case Machine = 'machine';
    case Shift = 'shift';
    case Customer = 'customer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WorkOrder => 'Work order',
            self::Machine => 'Mesin',
            self::Shift => 'Shift',
            self::Customer => 'Customer',
            self::Other => 'Lainnya',
        };
    }
}
