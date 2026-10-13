<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

/**
 * In-app is the record of what a user was told and is always delivered.
 * Email is an optional copy, off until an email service is approved
 * (DEC-014) and then subject to each user's preference.
 */
enum Channel: string
{
    case InApp = 'in_app';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::InApp => 'Di aplikasi',
            self::Email => 'Email',
        };
    }
}
