<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Domain\Enums;

/**
 * Estado da dupla (`registration_groups`).
 *
 * ADR 0001: "a dupla só é considerada completa quando as DUAS registrations
 * estiverem confirmadas". Este enum guarda essa conclusão de forma legível para
 * a tela — a fonte de verdade continua sendo o estado das inscrições membros.
 */
enum GroupStatus: string
{
    /** Falta aceite do parceiro, ou falta um dos dois pagar. */
    case FORMING = 'FORMING';

    /** As duas inscrições confirmadas. */
    case COMPLETE = 'COMPLETE';

    /**
     * Uma das inscrições virou terminal (cancelada ou expirada) e a dupla não se
     * completou. O aditivo §18 dá ao capitão a saída de trocar de dupla; até
     * que ele decida, o grupo fica aqui em vez de desaparecer.
     */
    case INCOMPLETE = 'INCOMPLETE';

    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::FORMING => 'Em formação',
            self::COMPLETE => 'Completa',
            self::INCOMPLETE => 'Incompleta',
            self::CANCELLED => 'Cancelada',
        };
    }
}
