<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Modules\Events\Application\EventDirectory;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Finance\Application\PlatformFinanceReport;
use App\Modules\Organizers\Application\OrganizerDirectory;
use App\Modules\Payments\Application\PaymentDirectory;
use App\Modules\Users\Application\UserDirectory;
use Illuminate\Http\JsonResponse;

/**
 * Dashboard global.
 *
 * Consumidor: `/admin` — a faixa de cartões no topo ("Usuários cadastrados",
 * "Jogadores", "Organizadores", "Eventos ativos", "Eventos realizados").
 *
 * Cada número vem do módulo dono, por contagem agregada — nunca carregando as
 * linhas para contar em PHP. São poucas queries de `COUNT`, e é assim que a
 * tela continua barata quando a base crescer (§26).
 *
 * Os cartões de arenas, denúncias, feedbacks e publicidade que a tela também
 * mostra **não** têm número aqui: são Fase 2 (§27.3) e seguem em mock. Devolver
 * zero seria pior do que não devolver — zero parece dado.
 */
final class AdminDashboardController
{
    /** `GET /admin/overview` */
    public function __invoke(
        UserDirectory $users,
        OrganizerDirectory $organizers,
        EventDirectory $events,
        PaymentDirectory $payments,
        PlatformFinanceReport $finance,
    ): JsonResponse {
        $userCounts = $users->counts();
        $eventsByStatus = $events->countsByStatus();
        $platform = $finance->platformTotals();

        /*
         * "Ativo" na tela é o evento que está em operação: publicado ou com
         * inscrições. Rascunho, finalizado e cancelado ficam de fora — é a
         * mesma leitura que a tela já fazia sobre o mock.
         */
        $active = ($eventsByStatus[EventStatus::PUBLISHED->value] ?? 0)
            + ($eventsByStatus[EventStatus::REGISTRATION_OPEN->value] ?? 0)
            + ($eventsByStatus[EventStatus::REGISTRATION_CLOSED->value] ?? 0);

        return new JsonResponse([
            'data' => [
                'users' => [
                    'total' => $userCounts->total,
                    'players' => $userCounts->players,
                    'organizers' => $userCounts->organizers,
                    'blocked' => $userCounts->blocked,
                ],
                'organizers' => [
                    'active' => $organizers->countActive(),
                ],
                'events' => [
                    'active' => $active,
                    'finished' => $eventsByStatus[EventStatus::FINISHED->value] ?? 0,
                    'draft' => $eventsByStatus[EventStatus::DRAFT->value] ?? 0,
                    'cancelled' => $eventsByStatus[EventStatus::CANCELLED->value] ?? 0,
                ],
                'payments' => [
                    'by_status' => $payments->countsByStatus(),
                ],
                'finance' => [
                    'gross_cents' => $platform->grossCents,
                    'platform_revenue_cents' => $platform->platformRevenueCents(),
                    'asaas_fee_cents' => $platform->asaasFeeCents,
                    'organizer_net_cents' => $platform->organizerNetCents,
                    'net_is_complete' => $platform->netIsComplete,
                ],
            ],
        ]);
    }
}
