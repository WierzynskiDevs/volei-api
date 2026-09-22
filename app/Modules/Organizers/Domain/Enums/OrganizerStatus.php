<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Domain\Enums;

/**
 * Situação administrativa do organizador.
 *
 * Espelha `OrganizerFinanceStatus` do baseline
 * (REGULAR | ATENCAO | BLOQUEADO, em `src/lib/finance-data.ts`).
 */
enum OrganizerStatus: string
{
    case REGULAR = 'REGULAR';
    case ATTENTION = 'ATTENTION';
    case BLOCKED = 'BLOCKED';

    public function label(): string
    {
        return match ($this) {
            self::REGULAR => 'Regular',
            self::ATTENTION => 'Atenção',
            self::BLOCKED => 'Bloqueado',
        };
    }

    /**
     * Organizador bloqueado não cria nem publica evento.
     * BRIEF §35: descumprir obrigação de reembolso pode levar a bloqueio.
     */
    public function canOperate(): bool
    {
        return $this !== self::BLOCKED;
    }
}
