<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

/**
 * Why an artwork was revised (PRD Lampiran: "Revisi Design harus mempunyai
 * reason category ... KPI Design tidak boleh membebankan revisi eksternal
 * sebagai kesalahan internal"). Only an internal error counts against the
 * Design team.
 */
enum RevisionCategory: string
{
    case InternalError = 'internal_error';
    case CustomerChange = 'customer_change';
    case SalesChange = 'sales_change';
    case ProductionIssue = 'production_issue';

    public function label(): string
    {
        return match ($this) {
            self::InternalError => 'Kesalahan internal',
            self::CustomerChange => 'Perubahan customer',
            self::SalesChange => 'Perubahan Sales',
            self::ProductionIssue => 'Masalah produksi',
        };
    }

    public function isInternal(): bool
    {
        return $this === self::InternalError;
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
