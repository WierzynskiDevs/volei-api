<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Infrastructure\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Consulta global de cobranças para uso administrativo (ADR 0010 §2).
 *
 * Consumidor: `/admin/pagamentos`, cujas abas são **grupos** de status —
 * "Pagos" reúne `CONFIRMED` e `RECEIVED`, "Reembolsados" reúne o estorno total
 * e o parcial. O mapeamento de grupo vive em `PaymentStatus::group()`, no
 * domínio: é conjunto fechado, e repetir a lista aqui garantiria que um status
 * novo aparecesse em zero abas sem ninguém perceber.
 *
 * Nada aqui recalcula dinheiro. Os valores são lidos como foram congelados na
 * criação da cobrança (CLAUDE.md §7.5); esta classe só filtra e ordena.
 */
final readonly class PaymentDirectory
{
    private const int MAX_PER_PAGE = 100;

    /**
     * @param  list<PaymentStatus>|null  $statuses
     * @return LengthAwarePaginator<int, Payment>
     */
    public function paginate(
        ?array $statuses = null,
        ?string $organizerId = null,
        ?string $eventId = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        return Payment::query()
            ->with(['user', 'event', 'organizer'])
            ->when(
                $statuses !== null && $statuses !== [],
                fn (Builder $q): Builder => $q->whereIn(
                    'status',
                    array_map(fn (PaymentStatus $s): string => $s->value, $statuses ?? []),
                ),
            )
            ->when($organizerId !== null, fn (Builder $q): Builder => $q->where('organizer_id', $organizerId))
            ->when($eventId !== null, fn (Builder $q): Builder => $q->where('event_id', $eventId))
            ->orderByDesc('created_at')
            ->paginate(min($perPage, self::MAX_PER_PAGE))
            ->withQueryString();
    }

    /**
     * Inscrições cujas cobranças estão em um dos status pedidos.
     *
     * Consumidor: os filtros de `/organizador/inscricoes`, que são status de
     * **pagamento** ("Pagos", "Pendentes") aplicados a uma lista de inscrições.
     *
     * Devolve ids em vez de aceitar um join: `Registrations` filtra o que é
     * dele com `whereIn`, e nenhum dos dois módulos consulta a tabela do outro
     * (CLAUDE.md §4.1).
     *
     * @param  list<PaymentStatus>  $statuses
     * @return list<string> registration_id
     */
    public function registrationIdsWithStatus(array $statuses, ?string $organizerId = null): array
    {
        if ($statuses === []) {
            return [];
        }

        /** @var list<string> */
        return Payment::query()
            ->whereIn('status', array_map(fn (PaymentStatus $s): string => $s->value, $statuses))
            ->when($organizerId !== null, fn (Builder $q): Builder => $q->where('organizer_id', $organizerId))
            ->distinct()
            ->pluck('registration_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * A cobrança mais recente de cada inscrição.
     *
     * Existe para que `Registrations` não precise conhecer a tabela `payments`
     * (CLAUDE.md §4.1): quem pergunta é o controller, que compõe as duas coisas
     * na resposta. Uma query para a página inteira, nunca uma por linha (§26).
     *
     * "Mais recente" e não "a única": uma inscrição pode ter cobrança falhada
     * seguida de nova tentativa (`FAILED → PENDING` é transição válida). A que
     * interessa ao atleta é sempre a última.
     *
     * @param  list<string>  $registrationIds
     * @return array<string, Payment> registration_id => cobrança
     */
    public function latestByRegistration(array $registrationIds): array
    {
        if ($registrationIds === []) {
            return [];
        }

        /** @var array<string, Payment> $latest */
        $latest = [];

        $payments = Payment::query()
            ->whereIn('registration_id', $registrationIds)
            ->orderBy('created_at')
            ->get();

        // A ordenação ascendente faz a última atribuição vencer — sem subquery
        // e sem window function, que nesta escala não se pagam.
        foreach ($payments as $payment) {
            $latest[(string) $payment->registration_id] = $payment;
        }

        return $latest;
    }

    /** Uma cobrança, com o necessário para a tela de detalhe. */
    public function find(string $paymentId): ?Payment
    {
        return Payment::query()
            ->with(['user', 'event', 'organizer', 'registration'])
            ->find($paymentId);
    }

    /**
     * Cobranças ainda abertas: quantas são e quanto somam.
     *
     * Vem de `payments`, e não do ledger, de propósito: o ledger só recebe
     * lançamento na **confirmação** (§7.9). Dinheiro que ainda não entrou não
     * tem — e não pode ter — lançamento contábil. Somá-lo junto do consolidado
     * apresentaria como receita algo que talvez nunca seja pago.
     *
     * @return array{count: int, gross_cents: int}
     */
    public function pendingSummary(?string $organizerId = null): array
    {
        $row = Payment::query()
            ->whereIn('status', PaymentStatus::openValues())
            ->when($organizerId !== null, fn (Builder $q): Builder => $q->where('organizer_id', $organizerId))
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(gross_cents), 0) AS gross')
            ->first();

        return [
            'count' => (int) ($row?->getAttribute('total') ?? 0),
            'gross_cents' => (int) ($row?->getAttribute('gross') ?? 0),
        ];
    }

    /**
     * Cobranças pagas e pendentes **por evento** de um organizador.
     *
     * Consumidor: a linha "N pagas · M pendentes" de `/organizador/eventos`.
     *
     * Uma query agregada para todos os eventos do organizador — a alternativa
     * seria uma consulta por linha da lista, que é o N+1 que o §26 proíbe.
     *
     * @return array<string, array{paid: int, pending: int}> event_id => contagens
     */
    public function countsByEventForOrganizer(string $organizerId): array
    {
        $paid = array_map(
            fn (PaymentStatus $s): string => $s->value,
            PaymentStatus::group('paid'),
        );
        $open = PaymentStatus::openValues();

        $rows = Payment::query()
            ->where('organizer_id', $organizerId)
            ->selectRaw('event_id, status, COUNT(*) AS total')
            ->groupBy('event_id', 'status')
            ->get();

        /** @var array<string, array{paid: int, pending: int}> $counts */
        $counts = [];

        foreach ($rows as $row) {
            $eventId = (string) $row->getAttribute('event_id');
            $status = $row->getAttribute('status');
            $value = $status instanceof PaymentStatus ? $status->value : (string) $status;
            $total = (int) $row->getAttribute('total');

            $counts[$eventId] ??= ['paid' => 0, 'pending' => 0];

            if (in_array($value, $paid, strict: true)) {
                $counts[$eventId]['paid'] += $total;
            } elseif (in_array($value, $open, strict: true)) {
                $counts[$eventId]['pending'] += $total;
            }
            // Falhada, cancelada e estornada não entram em nenhuma das duas
            // contagens: a tela pergunta "quantas pagas e quantas faltam pagar".
        }

        return $counts;
    }

    /**
     * Quantas cobranças em cada status. Alimenta os cartões do topo.
     *
     * @return array<string, int>
     */
    public function countsByStatus(?string $organizerId = null): array
    {
        /** @var array<string, int> */
        return Payment::query()
            ->when($organizerId !== null, fn (Builder $q): Builder => $q->where('organizer_id', $organizerId))
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }
}
