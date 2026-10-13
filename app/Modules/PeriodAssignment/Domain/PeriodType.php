<?php

declare(strict_types=1);

namespace App\Modules\PeriodAssignment\Domain;

/**
 * Classification of an assessment period. Start and end dates are always
 * entered explicitly: the business calendar (fiscal year, cut-off dates) is
 * not decided, so no calendar alignment is derived from the type. Periods of
 * the same type may not overlap for the same organization scope.
 */
enum PeriodType: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annual = 'annual';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Bulanan',
            self::Quarterly => 'Kuartalan',
            self::Annual => 'Tahunan',
            self::Custom => 'Rentang khusus',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}
