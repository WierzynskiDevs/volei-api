<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Enums;

enum NotificationDeliveryStatus: string
{
    case PENDING = 'PENDING';
    case SENT = 'SENT';
    case FAILED = 'FAILED';
}
