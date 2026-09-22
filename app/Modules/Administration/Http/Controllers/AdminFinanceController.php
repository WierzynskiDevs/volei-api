<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Modules\Events\Application\EventDirectory;
use App\Modules\Finance\Application\FinanceTotals;
use App\Modules\Finance\Application\PlatformFinanceReport;
use App\Modules\Organizers\Application\OrganizerDirectory;
use App\Modules\Payments\Application\PaymentDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Financeiro consolidado da plataforma.
 *
 * Consumidor: `/admin/financeiro` — cartões do topo, tabela por organizador
 * ("Organizador · Plano · Taxa atual · Eventos · GMV · Receita SaaS") e tabela
 * por evento ("Evento · GMV · Receita SaaS · Taxas Asaas · Reembolsos ·
 * Chargebacks").
 *
 * ## Três garantias que este endpoint não abre mão
 *
 * 1. **Receita da plataforma é só a taxa da plataforma.** A taxa do Asaas
 *    aparece como linha própria e nunca é somada à receita do SaaS (§7.6).
 * 2. **Nada é recalculado.** Os números vêm do ledger, que guarda o que foi
 *    congelado em cada cobrança (§7.5).
 * 3. **Líquido incompleto se declara.** Quando há pagamento confirmado sem taxa
 *    do gateway informada, `net_is_complete` vem `false` e a tela mostra
 *    "indisponível" — nunca um número que parece fechado e não está
 *    (ADR 0009 §5).
 *
 * Não é cacheado, de propósito: §20 proíbe cache de saldo e de status de
 * pagamento.
 */
final class AdminFinanceController
{
    /** `GET /admin/finance` */
    public function summary(
        Request $request,
        PlatformFinanceReport $report,
        OrganizerDirectory $organizers,
        EventDirectory $events,
        PaymentDirectory $payments,
    ): JsonResponse {
        $from = $request->filled('from') ? CarbonImmutable::parse((string) $request->string('from')) : null;
        $to = $request->filled('to') ? CarbonImmutable::parse((string) $request->string('to')) : null;

        $platform = $report->platformTotals($from, $to);
        $byOrganizer = $report->totalsByOrganizer($from, $to);
        $byEvent = $report->totalsByEvent($from, $to);

        /** @var list<string> $organizerIds */
        $organizerIds = array_keys($byOrganizer);
        /** @var list<string> $eventIds */
        $eventIds = array_keys($byEvent);

        $organizerModels = $organizers->findMany($organizerIds);
        $eventModels = $events->findMany($eventIds);

        $organizerRows = [];

        foreach ($byOrganizer as $organizerId => $totals) {
            $organizer = $organizerModels->get($organizerId);

            $organizerRows[] = [
                'organizer_id' => $organizerId,
                'organizer_name' => $organizer?->name,
                'plan_code' => $organizer?->plan?->code,
                // Taxa VIGENTE do plano — não é a taxa das cobranças passadas,
                // que ficou congelada em cada pagamento (§7.5).
                'current_platform_fee_basis_points' => $organizer?->plan?->platform_fee_basis_points,
                ...$this->money($totals),
            ];
        }

        $eventRows = [];

        foreach ($byEvent as $eventId => $totals) {
            $event = $eventModels->get($eventId);

            $eventRows[] = [
                'event_id' => $eventId,
                'event_name' => $event?->name,
                'event_slug' => $event?->slug,
                ...$this->money($totals),
            ];
        }

        // Maior GMV primeiro: a tela é de acompanhamento, e quem move mais
        // dinheiro é o que precisa aparecer antes.
        usort($organizerRows, fn (array $a, array $b): int => $b['gross_cents'] <=> $a['gross_cents']);
        usort($eventRows, fn (array $a, array $b): int => $b['gross_cents'] <=> $a['gross_cents']);

        $monthRows = [];

        foreach ($report->totalsByMonth($from, $to) as $month => $totals) {
            $monthRows[] = ['period' => $month, ...$this->money($totals)];
        }

        return new JsonResponse([
            'data' => [
                'period' => [
                    'from' => $from?->toIso8601String(),
                    'to' => $to?->toIso8601String(),
                ],
                'platform' => $this->money($platform),
                /*
                 * Pendentes vêm de `payments`, não do ledger: dinheiro que ainda
                 * não entrou não tem lançamento contábil (§7.9). São exibidos
                 * como expectativa, nunca somados ao consolidado.
                 */
                'pending' => $payments->pendingSummary(),
                'by_month' => $monthRows,
                'by_organizer' => $organizerRows,
                'by_event' => $eventRows,
            ],
        ]);
    }

    /**
     * Formato único de dinheiro em toda a resposta: inteiro em centavos com
     * sufixo `_cents` (§13). Um único lugar monta isso — repetir o array em
     * três pontos é como um campo acaba com nome diferente em cada tabela.
     *
     * @return array<string, int|bool>
     */
    private function money(FinanceTotals $totals): array
    {
        return [
            'gross_cents' => $totals->grossCents,
            'platform_revenue_cents' => $totals->platformRevenueCents(),
            'asaas_fee_cents' => $totals->asaasFeeCents,
            'organizer_net_cents' => $totals->organizerNetCents,
            'refunded_cents' => $totals->refundedCents,
            'chargeback_cents' => $totals->chargebackCents,
            'net_is_complete' => $totals->netIsComplete,
        ];
    }
}
