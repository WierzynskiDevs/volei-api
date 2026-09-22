<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Domain\Enums;

/**
 * Vínculo da conta do organizador com o gateway de pagamento.
 *
 * Espelha `OrganizerAsaasStatus` do baseline
 * (CONECTADA | PENDENTE | NAO_VINCULADA), mas com nome agnóstico de provedor:
 * o domínio não conhece "Asaas" (CLAUDE.md §14).
 */
enum PaymentAccountStatus: string
{
    case LINKED = 'LINKED';
    case PENDING = 'PENDING';
    case NOT_LINKED = 'NOT_LINKED';

    public function label(): string
    {
        return match ($this) {
            self::LINKED => 'Conectada',
            self::PENDING => 'Pendente',
            self::NOT_LINKED => 'Não vinculada',
        };
    }
}
