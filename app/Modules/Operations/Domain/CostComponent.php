<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

/**
 * What a costing estimate is compared on (PRD Lampiran: "akurasi estimasi
 * material, waktu mesin, dan tenaga kerja").
 */
enum CostComponent: string
{
    case Material = 'material';
    case MachineTime = 'machine_time';
    case Labor = 'labor';

    public function label(): string
    {
        return match ($this) {
            self::Material => 'Material',
            self::MachineTime => 'Waktu mesin',
            self::Labor => 'Tenaga kerja',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
