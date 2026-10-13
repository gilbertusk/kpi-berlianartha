<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
