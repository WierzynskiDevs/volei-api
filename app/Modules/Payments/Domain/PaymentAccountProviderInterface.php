<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Modules\Payments\Domain\DTO\AccountOnboardingRequest;
use App\Modules\Payments\Domain\DTO\AccountSnapshot;
use App\Modules\Payments\Domain\DTO\AccountStatusSnapshot;

/**
 * Contrato de provisionamento de conta do gateway (ADR 0018).
 *
 * Separado de `PaymentProviderInterface` (ADR 0009) de propósito: aquele é
 * sobre cobrança (N por organizador); este é sobre a conta em si (1 por
 * organizador, ciclo de vida diferente). O domínio não conhece o Asaas —
 * nenhum campo do gateway aparece nas assinaturas.
 *
 * Duas implementações: `FakePaymentAccountProvider` (dev e todos os testes,
 * sem rede — CLAUDE.md §22) e `AsaasPaymentAccountProvider` (produção).
 */
interface PaymentAccountProviderInterface
{
    /**
     * Abre a subconta. Chamada **fora** de transação de banco (CLAUDE.md §8):
     * é HTTP externo.
     */
    public function createAccount(AccountOnboardingRequest $request): AccountSnapshot;

    /**
     * Consulta a situação de aprovação da subconta.
     *
     * `$accountApiKey` é o da SUBCONTA (devolvido por `createAccount`), nunca
     * o nosso — é assim que o Asaas identifica de qual conta se fala.
     */
    public function fetchAccountStatus(string $externalAccountId, string $accountApiKey): AccountStatusSnapshot;

    /** Identificador do provedor. */
    public function name(): string;
}
