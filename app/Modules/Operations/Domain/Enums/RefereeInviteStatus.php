<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Enums;

/** Ciclo de vida do convite de juiz (ADR 0013 §5/§8). */
enum RefereeInviteStatus: string
{
    case NOT_SENT = 'NOT_SENT';
    case SENT = 'SENT';
    case ACCEPTED = 'ACCEPTED';

    public function label(): string
    {
        return match ($this) {
            self::NOT_SENT => 'Convite não enviado',
            self::SENT => 'Convite enviado',
            self::ACCEPTED => 'Aceito',
        };
    }
}
