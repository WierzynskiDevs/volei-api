<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Enums;

enum NotificationChannel: string
{
    case EMAIL = 'EMAIL';

    /** Reservado — nenhum job envia por este canal ainda (ver `NotificationType::REFEREE_INVITED`). */
    case SMS = 'SMS';
}
