<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Events\Application\EventDirectory;
use App\Modules\Finance\Application\FinanceTotals;
use App\Modules\Finance\Application\PlatformFinanceReport;
use App\Modules\Payments\Application\PaymentDirectory;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Financeiro do organizador.
 *
 * Consumidor: `/organizador/financeiro` — cartões do topo, a composição
 * "como o dinheiro é dividido" e a tabela por evento.
 *
 * ## O escopo vem da sessão, nunca do cliente
 *
 * Não existe parâmetro `organizer_id` nesta rota, e isso é a proteção contra
 * IDOR (CLAUDE.md §10): o organizador é resolvido a partir do usuário logado.
 * Passar o id de outro organizador na URL não muda nada porque a URL não o
 * aceita.
 *
 * ## O que este endpoint NÃO faz
 *
 * Não recalcula nada. Os números vêm do ledger, que guarda o que foi congelado
 * em cada cobrança (§7.5). E a taxa do Asaas aparece como **custo do
 * organizador**, nunca somada à receita da plataforma (§7.6).
 */
final class OrganizerFinanceController
{
    /** `GET /organizer/finance` */
    public function __invoke(
        Request $request,
        PlatformFinanceReport $report,
        PaymentDirectory $payments,
        EventDirectory $events,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $user->loadMissing('organizer.plan');
        $organizer = $user->organizer;

        /*
         * Conta sem perfil de organizador recebe 403, e não uma resposta
         * zerada: zerada pareceria "você não faturou nada", quando a verdade é
         * "você não é organizador".
         */
        if ($organizer === null) {
            abort(403);
        }

        $totals = $report->organizerTotals($organizer->id);
        $byEvent = $report->totalsByEventForOrganizer($organizer->id);

        /** @var list<string> $eventIds */
        $eventIds = array_keys($byEvent);
        $eventModels = $events->findMany($eventIds);

        $countsByEvent = $payments->countsByEventForOrganizer($organizer->id);

        $rows = [];

        foreach ($byEvent as $eventId => $eventTotals) {
            $event = $eventModels->get($eventId);
            $counts = $countsByEvent[$eventId] ?? ['paid' => 0, 'pending' => 0];

            $rows[] = [
                'event_id' => $eventId,
                'event_name' => $event?->name,
                'event_slug' => $event?->slug,
                'paid_count' => $counts['paid'],
                'pending_count' => $counts['pending'],
                ...$this->money($eventTotals),
            ];
        }

        /*
         * Evento com cobrança pendente e nenhuma paga não tem lançamento no
         * ledger — logo não apareceria em `$byEvent`. Ele precisa aparecer
         * assim mesmo: a linha "0 pagas · 3 pendentes" é a que o organizador
         * usa para saber quem cobrar.
         */
        foreach ($countsByEvent as $eventId => $counts) {
            if (isset($byEvent[$eventId])) {
                continue;
            }

            $event = $events->findMany([$eventId])->get($eventId);

            $rows[] = [
                'event_id' => $eventId,
                'event_name' => $event?->name,
                'event_slug' => $event?->slug,
                'paid_count' => $counts['paid'],
                'pending_count' => $counts['pending'],
                ...$this->money(FinanceTotals::empty()),
            ];
        }

        // Maior receita primeiro: é o evento que o organizador quer ver antes.
        usort($rows, fn (array $a, array $b): int => $b['gross_cents'] <=> $a['gross_cents']);

        $byStatus = $payments->countsByStatus($organizer->id);

        return new JsonResponse([
            'data' => [
                'organizer' => [
                    'id' => $organizer->id,
                    'name' => $organizer->name,
                    'plan' => $organizer->plan === null ? null : [
                        'code' => $organizer->plan->code,
                        'name' => $organizer->plan->name,
                        // Taxa VIGENTE. As cobranças já emitidas mantêm a que
                        // foi congelada nelas (§7.5).
                        'platform_fee_basis_points' => $organizer->plan->platform_fee_basis_points,
                    ],
                    'payment_account_status' => $organizer->payment_account_status->value,
                    'can_receive_payments' => $organizer->canReceivePayments(),
                ],
                'totals' => $this->money($totals),
                /*
                 * Pendentes vêm de `payments`, não do ledger: cobrança não paga
                 * não tem lançamento contábil (§7.9). É expectativa, e por isso
                 * viaja separada dos totais — somar as duas coisas apresentaria
                 * como receita algo que talvez nunca seja pago.
                 */
                'pending' => $payments->pendingSummary($organizer->id),
                'paid_count' => ($byStatus[PaymentStatus::CONFIRMED->value] ?? 0)
                    + ($byStatus[PaymentStatus::RECEIVED->value] ?? 0),
                'by_event' => $rows,
            ],
        ]);
    }

    /** @return array<string, int|bool> */
    private function money(FinanceTotals $totals): array
    {
        return [
            'gross_cents' => $totals->grossCents,
            'platform_fee_cents' => $totals->platformFeeCents,
            'asaas_fee_cents' => $totals->asaasFeeCents,
            'organizer_net_cents' => $totals->organizerNetCents,
            'refunded_cents' => $totals->refundedCents,
            'chargeback_cents' => $totals->chargebackCents,
            'net_is_complete' => $totals->netIsComplete,
        ];
    }
}
