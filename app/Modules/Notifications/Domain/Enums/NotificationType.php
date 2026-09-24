<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Enums;

/**
 * Gatilhos de notificação transacional (CLAUDE.md §20, B4 do plano de
 * continuação). Catálogo fechado — igual a `AuditAction`, só se acrescenta.
 */
enum NotificationType: string
{
    case PARTNER_INVITED = 'PARTNER_INVITED';
    case MATCH_READY = 'MATCH_READY';

    /**
     * Reservado: o convite de juiz é por telefone (`event_referees` não tem
     * e-mail), e não há gateway de SMS/WhatsApp pesquisado ou configurado
     * ainda — precisa da mesma pesquisa de provedor que o Asaas exigiu antes
     * de código (CLAUDE.md §14). Até lá, o organizador compartilha o link do
     * convite manualmente (mesmo padrão de "compartilhar link" do evento).
     */
    case REFEREE_INVITED = 'REFEREE_INVITED';
}
