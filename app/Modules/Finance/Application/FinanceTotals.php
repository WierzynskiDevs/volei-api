<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\LedgerEntryType;

/**
 * Agregação de um recorte do ledger (plataforma, organizador ou evento).
 *
 * ## Todos os campos são positivos, e isso é uma escolha
 *
 * No ledger, taxa e estorno são **negativos** por convenção de sinal
 * (`LedgerEntryType`). Aqui saem como magnitude positiva, porque a tela escreve
 * "Taxa da plataforma R$ 6,50", não "R$ -6,50". A troca de sinal acontece uma
 * única vez, neste construtor nomeado — nunca espalhada por controller ou por
 * componente de tela.
 *
 * `organizerNetCents` pode ser menor que `grossCents − platformFeeCents` quando
 * há pagamento confirmado cuja taxa do gateway ainda é desconhecida: nesse caso
 * o lançamento `ORGANIZER_NET` ainda não existe (ADR 0009 §5). O líquido não é
 * estimado aqui — `netIsComplete` diz se dá para confiar no número.
 */
final readonly class FinanceTotals
{
    public function __construct(
        public int $grossCents,
        public int $platformFeeCents,
        public int $asaasFeeCents,
        public int $organizerNetCents,
        public int $refundedCents,
        public int $chargebackCents,
        public bool $netIsComplete,
    ) {}

    /**
     * Monta a partir das somas por tipo, como o banco devolve (com sinal).
     *
     * @param  array<string, int>  $sumsByType
     */
    public static function fromSums(array $sumsByType): self
    {
        $gross = $sumsByType[LedgerEntryType::GROSS_PAYMENT->value] ?? 0;
        $platformFee = abs($sumsByType[LedgerEntryType::PLATFORM_FEE->value] ?? 0);
        $asaasFee = abs($sumsByType[LedgerEntryType::ASAAS_FEE->value] ?? 0);
        $net = $sumsByType[LedgerEntryType::ORGANIZER_NET->value] ?? 0;

        /*
         * O líquido só é confiável quando fecha a identidade do §7.7 sobre o
         * recorte inteiro. Se não fecha, é porque há pagamento sem taxa do
         * gateway informada — e aí a tela mostra "indisponível" em vez de um
         * número que parece completo e não é.
         */
        $netIsComplete = $gross > 0
            ? $net === $gross - $platformFee - $asaasFee
            : true;

        return new self(
            grossCents: $gross,
            platformFeeCents: $platformFee,
            asaasFeeCents: $asaasFee,
            organizerNetCents: $net,
            refundedCents: abs($sumsByType[LedgerEntryType::REFUND->value] ?? 0),
            chargebackCents: abs($sumsByType[LedgerEntryType::CHARGEBACK->value] ?? 0),
            netIsComplete: $netIsComplete,
        );
    }

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, 0, true);
    }

    /**
     * Receita da plataforma: só a taxa da plataforma (CLAUDE.md §7.6).
     *
     * Existe como método nomeado para que ninguém escreva
     * `platformFee + asaasFee` em algum lugar e infle o faturamento do SaaS com
     * dinheiro que é do gateway.
     */
    public function platformRevenueCents(): int
    {
        return $this->platformFeeCents;
    }
}
