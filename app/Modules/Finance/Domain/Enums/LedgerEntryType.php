<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Enums;

/**
 * Tipos de lançamento do ledger (CLAUDE.md §9).
 *
 * `ledger_entries` é append-only: erro **não** se corrige com update, se corrige
 * com lançamento de estorno. Por isso `REFUND` e `CHARGEBACK` são tipos, não
 * estados de uma linha existente.
 *
 * Convenção de sinal, fixada aqui e em nenhum outro lugar:
 *
 *  - `GROSS_PAYMENT` é **positivo** — entrou dinheiro.
 *  - `PLATFORM_FEE` e `ASAAS_FEE` são **negativos** — saíram do bruto.
 *  - `ORGANIZER_NET` é **positivo** — é o que sobra para o organizador.
 *  - `REFUND` e `CHARGEBACK` são **negativos** — dinheiro saindo.
 *
 * Consequência: a soma de `GROSS_PAYMENT + PLATFORM_FEE + ASAAS_FEE` de um
 * pagamento tem de ser igual a `ORGANIZER_NET`. Essa é a conferência do §7.4
 * feita sobre a trilha, e não só sobre o cálculo.
 */
enum LedgerEntryType: string
{
    case GROSS_PAYMENT = 'GROSS_PAYMENT';
    case PLATFORM_FEE = 'PLATFORM_FEE';
    case ASAAS_FEE = 'ASAAS_FEE';
    case ORGANIZER_NET = 'ORGANIZER_NET';
    case REFUND = 'REFUND';
    case CHARGEBACK = 'CHARGEBACK';

    public function label(): string
    {
        return match ($this) {
            self::GROSS_PAYMENT => 'Pagamento bruto',
            self::PLATFORM_FEE => 'Taxa da plataforma',
            self::ASAAS_FEE => 'Taxa do Asaas',
            self::ORGANIZER_NET => 'Líquido do organizador',
            self::REFUND => 'Reembolso',
            self::CHARGEBACK => 'Chargeback',
        };
    }

    /** O sinal esperado do valor deste tipo. Vale `-1`, `0` ou `1`. */
    public function expectedSign(): int
    {
        return match ($this) {
            self::GROSS_PAYMENT, self::ORGANIZER_NET => 1,
            self::PLATFORM_FEE, self::ASAAS_FEE, self::REFUND, self::CHARGEBACK => -1,
        };
    }

    /**
     * É receita da **plataforma**?
     *
     * Só a taxa da plataforma. `CLAUDE.md` §7.6: a taxa do Asaas é custo do
     * organizador e **nunca** é contabilizada como receita da plataforma —
     * confundir os dois inflaria o faturamento do SaaS com dinheiro que é do
     * gateway.
     */
    public function isPlatformRevenue(): bool
    {
        return $this === self::PLATFORM_FEE;
    }

    /** Compõe o saldo do organizador. */
    public function affectsOrganizerBalance(): bool
    {
        return match ($this) {
            self::ORGANIZER_NET, self::REFUND, self::CHARGEBACK => true,
            default => false,
        };
    }
}
