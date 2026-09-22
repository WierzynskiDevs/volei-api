<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Infrastructure\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Agregação financeira da plataforma, lida do ledger (ADR 0010 §2).
 *
 * Consumidor: `/admin/financeiro` — cartões do topo, tabela por organizador e
 * tabela por evento.
 *
 * ## Por que o ledger, e não a tabela `payments`
 *
 * Somar `payments` daria quase o mesmo número quase sempre, e é exatamente o
 * "quase" que interessa: estorno e chargeback são **lançamentos novos**
 * (`ledger_entries` é append-only, §9), não alterações da linha do pagamento.
 * Um relatório que soma `payments` mostra dinheiro que já voltou para o atleta
 * como se ainda fosse receita.
 *
 * O ledger também é a trilha conferida por CHECK de sinal no banco, então é a
 * fonte que já provou estar coerente.
 */
final readonly class PlatformFinanceReport
{
    /** Totais consolidados da plataforma inteira. */
    public function platformTotals(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): FinanceTotals
    {
        return FinanceTotals::fromSums($this->sums($this->scoped($from, $to)));
    }

    /**
     * Totais de **um** organizador.
     *
     * Consumidor: `/organizador/financeiro`. O `organizerId` vem sempre da
     * sessão, nunca de parâmetro do cliente (CLAUDE.md §10) — quem chama é que
     * garante isso, e o controller do módulo é o único que chama.
     */
    public function organizerTotals(
        string $organizerId,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): FinanceTotals {
        return FinanceTotals::fromSums($this->sums($this->scoped($from, $to, $organizerId)));
    }

    /**
     * Totais por evento, restritos a um organizador.
     *
     * @return array<string, FinanceTotals> event_id => totais
     */
    public function totalsByEventForOrganizer(
        string $organizerId,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): array {
        return $this->groupedTotals('event_id', $from, $to, $organizerId);
    }

    /**
     * Totais por organizador, do maior GMV para o menor.
     *
     * Uma query agregada para a tabela inteira — nunca uma por linha (§26).
     *
     * @return array<string, FinanceTotals> organizer_id => totais
     */
    public function totalsByOrganizer(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        return $this->groupedTotals('organizer_id', $from, $to);
    }

    /**
     * Totais por evento.
     *
     * @return array<string, FinanceTotals> event_id => totais
     */
    public function totalsByEvent(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        return $this->groupedTotals('event_id', $from, $to);
    }

    /**
     * Totais por mês, do mais antigo para o mais recente.
     *
     * Consumidor: o gráfico "Receita por período" de `/admin/financeiro`.
     *
     * O agrupamento é feito no banco com `DATE_TRUNC`, não em PHP: carregar
     * todos os lançamentos para somar por mês em memória cresceria linearmente
     * com o volume da plataforma (§26), que é exatamente o que um relatório não
     * pode fazer.
     *
     * O mês é calculado em **UTC**, como tudo que é persistido (§6). Relatório
     * gerencial num fuso e banco em outro produz meses que não fecham.
     *
     * @return array<string, FinanceTotals> 'YYYY-MM' => totais
     */
    public function totalsByMonth(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        /** @var array<string, array<string, int>> $byMonth */
        $byMonth = [];

        $rows = $this->scoped($from, $to)
            ->selectRaw("TO_CHAR(DATE_TRUNC('month', created_at AT TIME ZONE 'UTC'), 'YYYY-MM') AS period, type, SUM(amount_cents) AS total")
            ->groupBy('period', 'type')
            ->orderBy('period')
            ->get();

        foreach ($rows as $row) {
            /** @var string $period */
            $period = $row->getAttribute('period');
            $type = $row->getAttribute('type');

            $key = $type instanceof \BackedEnum ? (string) $type->value : (string) $type;

            $byMonth[$period][$key] = (int) $row->getAttribute('total');
        }

        return array_map(
            fn (array $sums): FinanceTotals => FinanceTotals::fromSums($sums),
            $byMonth,
        );
    }

    /**
     * @param  'organizer_id'|'event_id'  $column  literal do código, nunca entrada de usuário
     * @return array<string, FinanceTotals>
     */
    private function groupedTotals(
        string $column,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        ?string $organizerId = null,
    ): array {
        /** @var array<string, array<string, int>> $byKey */
        $byKey = [];

        $rows = $this->scoped($from, $to, $organizerId)
            ->selectRaw($column.', type, SUM(amount_cents) AS total')
            ->whereNotNull($column)
            ->groupBy($column, 'type')
            ->get();

        foreach ($rows as $row) {
            /** @var string $key */
            $key = $row->getAttribute($column);
            /** @var string $type */
            $type = $row->getAttribute('type') instanceof \BackedEnum
                ? (string) $row->getAttribute('type')->value
                : (string) $row->getAttribute('type');

            $byKey[$key][$type] = (int) $row->getAttribute('total');
        }

        return array_map(
            fn (array $sums): FinanceTotals => FinanceTotals::fromSums($sums),
            $byKey,
        );
    }

    /**
     * Soma por tipo de lançamento.
     *
     * @param  Builder<LedgerEntry>  $query
     * @return array<string, int>
     */
    private function sums(Builder $query): array
    {
        /** @var array<string, int> */
        return $query
            ->selectRaw('type, SUM(amount_cents) AS total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * Recorte de período. O ledger não tem `updated_at` — `created_at` é o
     * instante do fato, e é imutável.
     *
     * @return Builder<LedgerEntry>
     */
    private function scoped(
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        ?string $organizerId = null,
    ): Builder {
        return LedgerEntry::query()
            ->when($from !== null, fn (Builder $q): Builder => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $q): Builder => $q->where('created_at', '<=', $to))
            ->when($organizerId !== null, fn (Builder $q): Builder => $q->where('organizer_id', $organizerId));
    }
}
