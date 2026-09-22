<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Modules\Payments\Domain\Exceptions\BreakdownDoesNotBalanceException;
use App\Shared\Domain\FeeRate;
use App\Shared\Domain\Money;

/**
 * Decomposição financeira de um pagamento (CLAUDE.md §7).
 *
 *     gross − platform_fee − asaas_fee = organizer_net
 *
 * Domínio puro: sem Eloquent, sem IO, testável sem banco (§4.3 e §22). É aqui
 * que a soma das partes é conferida contra o bruto — §7.4 é explícito em que,
 * se não fechar, a operação **falha**, não "corrige".
 *
 * ## Por que a taxa do Asaas é opcional
 *
 * A taxa do gateway só é conhecida quando o Asaas responde (ADR 0009 §5): ela
 * vem de `gross − netValue`, nunca de heurística. Antes disso o líquido do
 * organizador é **desconhecido** — o que é diferente de zero. Um breakdown sem
 * taxa do gateway devolve `organizerNet() === null`, e o dashboard mostra
 * "indisponível" em vez de mentir um número.
 */
final readonly class PaymentBreakdown
{
    private function __construct(
        public Money $gross,
        public Money $platformFee,
        public FeeRate $platformFeeRate,
        public Money $platformFeeFixed,
        public ?Money $asaasFee,
    ) {}

    /**
     * Calcula a taxa da plataforma sobre o **bruto**.
     *
     * Sobre o bruto, e não sobre o líquido, de propósito: é a definição do §7.7.
     * Calcular sobre o líquido faria a receita do SaaS variar com a taxa do
     * gateway, que muda por método de pagamento (ADR 0009 §1).
     *
     * A taxa do plano tem parte percentual e parte fixa — as duas somam.
     */
    public static function forCharge(
        Money $gross,
        FeeRate $platformFeeRate,
        Money $platformFeeFixed,
        ?Money $asaasFee = null,
    ): self {
        $gross->assertNotNegative('valor bruto da cobrança');
        $platformFeeFixed->assertNotNegative('parte fixa da taxa da plataforma');
        $asaasFee?->assertNotNegative('taxa do Asaas');

        $platformFee = $gross->percentage($platformFeeRate)->add($platformFeeFixed);

        $breakdown = new self($gross, $platformFee, $platformFeeRate, $platformFeeFixed, $asaasFee);
        $breakdown->assertBalances();

        return $breakdown;
    }

    /**
     * Reconstrói a partir de valores **já persistidos**.
     *
     * Usado na leitura de um pagamento existente. Não recalcula nada: a taxa é
     * congelada na criação (§7.5) e recalcular pagamento passado é proibido.
     */
    public static function fromPersisted(
        Money $gross,
        Money $platformFee,
        FeeRate $platformFeeRate,
        Money $platformFeeFixed,
        ?Money $asaasFee,
    ): self {
        $breakdown = new self($gross, $platformFee, $platformFeeRate, $platformFeeFixed, $asaasFee);
        $breakdown->assertBalances();

        return $breakdown;
    }

    /**
     * Líquido do organizador. `null` = taxa do gateway ainda desconhecida.
     *
     * `null` e não zero: zero significaria "o organizador não recebe nada", que
     * é uma afirmação financeira falsa.
     */
    public function organizerNet(): ?Money
    {
        if ($this->asaasFee === null) {
            return null;
        }

        return $this->gross->subtract($this->platformFee)->subtract($this->asaasFee);
    }

    /** Preenche a taxa do gateway quando o Asaas informa. */
    public function withAsaasFee(Money $asaasFee): self
    {
        return self::fromPersisted(
            $this->gross,
            $this->platformFee,
            $this->platformFeeRate,
            $this->platformFeeFixed,
            $asaasFee,
        );
    }

    /**
     * Invariante do §7.4: as partes não podem exceder o bruto.
     *
     * Cobrança de valor muito baixo é o caso real: R$ 1,00 com taxa fixa de
     * R$ 0,50 mais taxa de gateway de R$ 1,99 daria líquido negativo. Falhar
     * aqui evita criar no gateway um split que ele recusaria (ADR 0009 §1) e
     * evita gravar um pagamento cuja decomposição não fecha.
     */
    private function assertBalances(): void
    {
        if ($this->platformFee->greaterThan($this->gross)) {
            throw BreakdownDoesNotBalanceException::platformFeeExceedsGross(
                $this->gross->cents,
                $this->platformFee->cents,
            );
        }

        $net = $this->organizerNet();

        if ($net !== null && $net->isNegative()) {
            throw BreakdownDoesNotBalanceException::negativeNet(
                gross: $this->gross->cents,
                platformFee: $this->platformFee->cents,
                asaasFee: (int) $this->asaasFee?->cents,
                net: $net->cents,
            );
        }

        /*
         * Conferência explícita da identidade. Redundante com a aritmética de
         * `Money`, e é essa a intenção: se algum dia alguém trocar a
         * implementação de `subtract`, o teste de dinheiro falha aqui em vez de
         * silenciosamente pagar errado.
         */
        if ($net !== null) {
            $sum = $net->add($this->platformFee)->add($this->asaasFee ?? Money::zero());

            if (! $sum->equals($this->gross)) {
                throw BreakdownDoesNotBalanceException::sumMismatch(
                    $this->gross->cents,
                    $sum->cents,
                );
            }
        }
    }
}
