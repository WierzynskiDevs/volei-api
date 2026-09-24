<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\DTO;

use App\Shared\Domain\Money;

/**
 * Pedido de abertura de subconta, em vocabulário nosso (ADR 0018).
 *
 * Nenhum campo do Asaas aparece aqui (`cpfCnpj`, `mobilePhone`, `incomeValue`
 * continuam nomes nossos) — a tradução é responsabilidade de
 * `AsaasPaymentAccountProvider`, na infraestrutura (CLAUDE.md §14).
 */
final readonly class AccountOnboardingRequest
{
    public function __construct(
        public string $name,
        public string $email,
        /** CPF ou CNPJ, só dígitos — já validado antes de chegar aqui (ADR 0014). */
        public string $documentNumber,
        /** Só dígitos, com DDD. */
        public string $mobilePhone,
        public Money $monthlyIncome,
        public string $address,
        public string $addressNumber,
        /** Bairro. */
        public string $province,
        /** CEP, só dígitos. */
        public string $postalCode,
        /** Elo entre a subconta e o organizador — igual a `externalReference` de cobrança. */
        public string $externalReference,
    ) {}
}
