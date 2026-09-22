<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Providers;

use App\Modules\Payments\Domain\DTO\ChargeRequest;
use App\Modules\Payments\Domain\DTO\ChargeSnapshot;
use App\Modules\Payments\Domain\DTO\WebhookEvent;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\PaymentProviderInterface;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Provedor determinístico para desenvolvimento e testes (ADR 0004).
 *
 * É este que roda em **todos** os testes automatizados: `CLAUDE.md` §22 exige
 * que teste financeiro não dependa de rede externa.
 *
 * ## O que ele simula de verdade
 *
 * Não é um stub que devolve "ok". Ele reproduz as três características do Asaas
 * que o nosso código precisa suportar (docs/asaas.md):
 *
 *  1. **dois estágios** — `CONFIRMED` e depois `RECEIVED`, com a taxa do gateway
 *     só aparecendo quando o pagamento é confirmado;
 *  2. **entrega "at least once"** — `webhookFor()` pode ser chamado várias vezes
 *     com o mesmo `providerEventId`, para exercitar a idempotência;
 *  3. **eventos sem efeito** — `PAYMENT_CHECKOUT_VIEWED` volta com
 *     `resultingStatus` nulo, para exercitar o caminho `IGNORED`.
 *
 * ## Taxa do gateway
 *
 * O valor abaixo é uma constante **de simulação**, e é por isso que ela vive
 * aqui e não em `config/`: colocá-la em configuração faria parecer que existe
 * uma taxa conhecida do Asaas. Não existe — ADR 0004 proíbe tratar heurística
 * como verdade, e em produção o valor vem de `gross − netValue` do gateway.
 */
final class FakePaymentProvider implements PaymentProviderInterface
{
    /** Taxa simulada: R$ 1,99. Não representa a taxa real de nenhum método. */
    private const int SIMULATED_FEE_CENTS = 199;

    public function name(): string
    {
        return 'fake';
    }

    public function createCharge(ChargeRequest $request): ChargeSnapshot
    {
        /*
         * Nasce PENDING, como no gateway real: a cobrança existe e espera
         * pagamento. Taxa do gateway ainda desconhecida — ela só é informada
         * quando há pagamento (ADR 0009 §5).
         */
        return new ChargeSnapshot(
            providerPaymentId: 'fake_'.Str::lower(Str::random(20)),
            status: PaymentStatus::PENDING,
            gross: $request->gross,
            gatewayFee: null,
            confirmedAt: null,
            receivedAt: null,
            checkoutUrl: 'https://sandbox.fake-provider.local/c/'.$request->externalReference,
            pixPayload: $request->method->value === 'PIX'
                ? '00020126FAKE'.mb_strtoupper(Str::random(24))
                : null,
        );
    }

    /**
     * Consulta. Sem estado persistido, devolve o que foi pedido — quem controla
     * o cenário no teste é `snapshotFor()`.
     */
    public function fetchCharge(string $providerPaymentId): ChargeSnapshot
    {
        return new ChargeSnapshot(
            providerPaymentId: $providerPaymentId,
            status: PaymentStatus::PENDING,
            gross: Money::zero(),
            gatewayFee: null,
            confirmedAt: null,
            receivedAt: null,
        );
    }

    public function refundCharge(string $providerPaymentId, ?Money $amount, string $reason): ChargeSnapshot
    {
        return new ChargeSnapshot(
            providerPaymentId: $providerPaymentId,
            status: $amount === null
                ? PaymentStatus::REFUNDED
                : PaymentStatus::PARTIALLY_REFUNDED,
            gross: $amount ?? Money::zero(),
            gatewayFee: Money::fromCents(self::SIMULATED_FEE_CENTS),
            confirmedAt: null,
            receivedAt: null,
            refundedTotal: $amount,
        );
    }

    /**
     * O fake aceita qualquer webhook: verificação de autenticidade é
     * responsabilidade da implementação real, e testá-la aqui testaria o fake.
     *
     * O teste de recusa de token inválido roda contra o `AsaasPaymentProvider`.
     *
     * @param  array<string, string|array<int, string|null>|null>  $headers
     */
    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        return true;
    }

    /** @param  array<string, mixed>  $payload */
    public function parseWebhook(array $payload): ?WebhookEvent
    {
        $eventId = $payload['id'] ?? null;
        $eventType = $payload['event'] ?? null;

        if (! is_string($eventId) || ! is_string($eventType)) {
            return null;
        }

        /** @var array<string, mixed> $charge */
        $charge = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];

        $status = match ($eventType) {
            'PAYMENT_CONFIRMED' => PaymentStatus::CONFIRMED,
            'PAYMENT_RECEIVED' => PaymentStatus::RECEIVED,
            'PAYMENT_OVERDUE' => PaymentStatus::OVERDUE,
            'PAYMENT_REFUNDED' => PaymentStatus::REFUNDED,
            'PAYMENT_PARTIALLY_REFUNDED' => PaymentStatus::PARTIALLY_REFUNDED,
            'PAYMENT_CHARGEBACK_REQUESTED' => PaymentStatus::CHARGEBACK,
            'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED' => PaymentStatus::FAILED,
            // Reconhecido, sem efeito no domínio → caminho IGNORED.
            'PAYMENT_CHECKOUT_VIEWED', 'PAYMENT_BANK_SLIP_VIEWED', 'PAYMENT_CREATED' => null,
            default => null,
        };

        return new WebhookEvent(
            providerEventId: $eventId,
            providerEventType: $eventType,
            providerPaymentId: is_string($charge['id'] ?? null) ? $charge['id'] : null,
            externalReference: is_string($charge['externalReference'] ?? null)
                ? $charge['externalReference']
                : null,
            resultingStatus: $status,
            gatewayFee: $status !== null && $status !== PaymentStatus::OVERDUE
                ? Money::fromCents(self::SIMULATED_FEE_CENTS)
                : null,
            occurredAt: CarbonImmutable::now(),
        );
    }

    /* ------------------------------------------------------------------ *
     * Auxiliares de teste
     *
     * Vivem aqui, e não numa classe de teste, porque descrevem o formato do
     * webhook que este provedor entende — mesma razão pela qual o parser vive
     * aqui.
     * ------------------------------------------------------------------ */

    /**
     * Monta o corpo de um webhook.
     *
     * `$eventId` é explícito para que o teste possa repetir o **mesmo** evento e
     * verificar a idempotência (entrega "at least once").
     *
     * @return array<string, mixed>
     */
    public static function webhookBody(
        string $eventType,
        string $providerPaymentId,
        string $externalReference,
        string $eventId,
        ?int $valueCents = null,
    ): array {
        return [
            'id' => $eventId,
            'event' => $eventType,
            'dateCreated' => CarbonImmutable::now()->toIso8601String(),
            'payment' => [
                'id' => $providerPaymentId,
                'externalReference' => $externalReference,
                'value' => $valueCents === null ? null : $valueCents / 100,
                'netValue' => $valueCents === null
                    ? null
                    : ($valueCents - self::SIMULATED_FEE_CENTS) / 100,
            ],
        ];
    }

    public static function simulatedFeeCents(): int
    {
        return self::SIMULATED_FEE_CENTS;
    }
}
