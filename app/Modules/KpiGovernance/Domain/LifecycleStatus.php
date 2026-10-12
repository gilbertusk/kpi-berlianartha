<?php

declare(strict_types=1);

namespace App\Modules\KpiGovernance\Domain;

/**
 * Lifecycle shared by KPI definition versions and template versions:
 * DRAFT → REVIEW → PUBLISHED → RETIRED, with REVIEW → DRAFT when the
 * reviewer returns a version. Content is editable only in DRAFT.
 */
enum LifecycleStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Published = 'published';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Review => 'Review',
            self::Published => 'Published',
            self::Retired => 'Retired',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::Review->value],
            self::Review->value => [self::Draft->value, self::Published->value],
            self::Published->value => [self::Retired->value],
            self::Retired->value => [],
        ];
    }
}
