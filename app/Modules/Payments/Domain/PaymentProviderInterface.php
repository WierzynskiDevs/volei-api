<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Modules\Payments\Domain\DTO\ChargeRequest;
use App\Modules\Payments\Domain\DTO\ChargeSnapshot;
use App\Modules\Payments\Domain\DTO\WebhookEvent;
use App\Shared\Domain\Money;

/**
 * Contrato do provedor de pagamento (CLAUDE.md §14, ADR 0004).
 *
 * O domínio conhece **este** contrato, nunca o Asaas. Nenhum tipo, campo ou
 * string do gateway aparece nas assinaturas — trocar de provedor é escrever
 * outra implementação, não refatorar o domínio.
 *
 * Duas implementações: `FakePaymentProvider` (desenvolvimento e todos os testes,
 * sem rede — §22) e `AsaasPaymentProvider` (produção).
 *
 * ## Sobre exceções
 *
 * Falha de integração é **explícita**: as implementações lançam
 * `PaymentProviderException`. `CLAUDE.md` §25 — falha externa nunca vira
 * sucesso, e nunca é engolida.
 */
interface PaymentProviderInterface
{
    /**
     * Cria a cobrança no gateway.
     *
     * Chamada **fora** de transação de banco (CLAUDE.md §8): é HTTP externo.
     */
    public function createCharge(ChargeRequest $request): ChargeSnapshot;

    /**
     * Consulta o estado atual no gateway.
     *
     * É a metade "reconciliação" da regra do §14: a confirmação vem de webhook
     * verificado **e** de consulta. Webhook perdido não pode significar dinheiro
     * perdido.
     */
    public function fetchCharge(string $providerPaymentId): ChargeSnapshot;

    /**
     * Estorna, total ou parcialmente.
     *
     * `null` em `$amount` = estorno total. Atenção: o Asaas devolve o estorno
     * como pedido, e ele só está concluído quando o status do refund vira `DONE`
     * (docs/asaas.md §5) — por isso o retorno é um snapshot, não um booleano.
     */
    public function refundCharge(string $providerPaymentId, ?Money $amount, string $reason): ChargeSnapshot;

    /**
     * Valida a autenticidade do webhook.
     *
     * No Asaas é token compartilhado no header `asaas-access-token`, não
     * assinatura HMAC do corpo — a comparação tem de ser em tempo constante
     * (ADR 0009 §4).
     *
     * @param  array<string, string|array<int, string|null>|null>  $headers
     */
    public function verifyWebhook(array $headers, string $rawBody): bool;

    /**
     * Normaliza o payload do webhook.
     *
     * Devolve `null` quando o corpo não é um evento reconhecível — o que é
     * diferente de evento sem efeito (esse volta com `resultingStatus` nulo).
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(array $payload): ?WebhookEvent;

    /** Identificador do provedor, gravado em `payments.provider`. */
    public function name(): string;
}
