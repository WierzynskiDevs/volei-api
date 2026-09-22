<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Enums;

/**
 * Natureza do evento.
 *
 * O baseline usa estes seis valores em `EventItem.eventType` e os exibe como
 * `<Tag>` na página do evento. Mantidos como enum para não voltarem a ser
 * string livre (CLAUDE.md §5).
 *
 * RANKING aqui é apenas classificação editorial do evento — o motor de ranking
 * é Fase 2 e nada neste enum o antecipa.
 */
enum EventType: string
{
    case COMPETITIVE = 'COMPETITIVE';
    case SOCIAL = 'SOCIAL';
    case RANKING = 'RANKING';
    case FRIENDLY = 'FRIENDLY';
    case LEAGUE = 'LEAGUE';
    case SPECIAL = 'SPECIAL';

    /** Rótulo do baseline — a UI exibe o valor em caixa alta, sem acento. */
    public function label(): string
    {
        return match ($this) {
            self::COMPETITIVE => 'COMPETITIVO',
            self::SOCIAL => 'SOCIAL',
            self::RANKING => 'RANKING',
            self::FRIENDLY => 'AMISTOSO',
            self::LEAGUE => 'LIGA',
            self::SPECIAL => 'ESPECIAL',
        };
    }
}
