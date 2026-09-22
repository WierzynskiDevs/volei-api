<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\DTO;

use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;

/**
 * Pedido de cobrança, em vocabulário nosso.
 *
 * Nenhum campo do Asaas aparece aqui (`billingType`, `customer`, `walletId`) —
 * a tradução é responsabilidade do `AsaasPaymentProvider`, na infraestrutura
 * (CLAUDE.md §14). Trocar de gateway é escrever outra implementação da
 * interface, não mexer nisto.
 */
final readonly class ChargeRequest
{
    public function __construct(
        public Money $gross,
        public PaymentMethod $method,
        public CarbonImmutable $dueDate,
        /**
         * Referência externa: o id do nosso pagamento. É o elo entre a cobrança
         * do gateway e a inscrição (`CLAUDE.md` §28) e o que torna a
         * reconciliação possível.
         */
        public string $externalReference,
        public string $description,
        /** Nome do pagador. Sem e-mail nem telefone além do necessário (§12). */
        public string $payerName,
        public string $payerEmail,
        /**
         * Taxa da plataforma a ser repassada por split, em **valor fixo**.
         *
         * Fixo e não percentual: o percentual do Asaas incide sobre o
         * `netValue`, o que faria a receita do SaaS variar com a taxa do
         * gateway (ADR 0009 §1).
         */
        public Money $platformFee,
    ) {}
}
