<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Http\Controllers;

use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Organizers\Infrastructure\Models\OrganizerPlanHistory;
use App\Modules\Organizers\Infrastructure\Models\Plan;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plano do organizador: o atual, os disponíveis e o histórico.
 *
 * Consumidor: `/organizador/plano`.
 *
 * ## Só leitura
 *
 * Não existe aqui uma rota para **trocar** de plano, e a ausência é deliberada:
 * mudança de plano tem efeito financeiro versionado (§7.8) — precisa de regra de
 * vigência, cobrança da mensalidade e registro em `organizer_plan_history`, que
 * ninguém desenhou ainda. A tela mostra os planos; a contratação continua sendo
 * conversa fora do produto.
 *
 * ## Por que os planos vêm por aqui e não numa rota pública
 *
 * O único consumidor é esta tela, que já é autenticada. Uma rota pública
 * `/plans` seria endpoint sem consumidor real (§27.13) — e expor a tabela de
 * preços sem tela que a use é superfície que ninguém revisa.
 */
final class OrganizerPlanController
{
    /** `GET /organizer/plan` */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $user->loadMissing(['organizer.plan', 'organizer.planHistory.plan']);
        $organizer = $user->organizer;

        if (! $organizer instanceof Organizer) {
            abort(403);
        }

        $available = Plan::query()
            ->where('status', 'ACTIVE')
            ->orderBy('sort_order')
            ->get();

        return new JsonResponse([
            'data' => [
                'organizer' => [
                    'id' => $organizer->id,
                    'name' => $organizer->name,
                    'payment_account_status' => $organizer->payment_account_status->value,
                    'payment_account_status_label' => $organizer->payment_account_status->label(),
                    'can_receive_payments' => $organizer->canReceivePayments(),
                ],
                'current_plan_code' => $organizer->plan?->code,
                'available' => $available->map(fn (Plan $plan): array => $this->planPayload($plan))->all(),
                'history' => $organizer->planHistory
                    ->sortByDesc('effective_at')
                    ->map(fn (OrganizerPlanHistory $entry): array => [
                        'id' => $entry->id,
                        'plan_code' => $entry->plan?->code,
                        'plan_name' => $entry->plan?->name,
                        /*
                         * A taxa da linha do histórico é a que valia na época —
                         * é ela que explica por que uma cobrança antiga tem
                         * percentual diferente do de hoje (§7.5).
                         */
                        'platform_fee_basis_points' => $entry->platform_fee_basis_points,
                        'note' => $entry->note,
                        'effective_at' => $entry->effective_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function planPayload(Plan $plan): array
    {
        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            // Dinheiro sempre em centavos, com sufixo `_cents` (§13).
            'monthly_price_cents' => $plan->monthly_price_cents,
            'platform_fee_basis_points' => $plan->platform_fee_basis_points,
            'platform_fee_fixed_cents' => $plan->platform_fee_fixed_cents,
            // `null` = ilimitado, e não zero — zero seria "não pode criar nada".
            'event_limit' => $plan->event_limit,
            'registration_limit' => $plan->registration_limit,
            'features' => $plan->features,
        ];
    }
}
