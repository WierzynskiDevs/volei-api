<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Providers;

use App\Modules\Payments\Domain\DTO\ChargeRequest;
use App\Modules\Payments\Domain\DTO\ChargeSnapshot;
use App\Modules\Payments\Domain\DTO\WebhookEvent;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Exceptions\PaymentProviderException;
use App\Modules\Payments\Domain\PaymentProviderInterface;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Integração Asaas.
 *
 * Escrito a partir do resumo verificado em `docs/asaas.md` (doc oficial
 * consultada em 26/08/2026) e das decisões do ADR 0009. `CLAUDE.md` §14 proíbe
 * implementar de memória — nada aqui foi suposto: o que a doc não respondeu está
 * marcado como pendência no `docs/asaas.md` §8 e **não** virou código
 * adivinhado.
 *
 * Esta classe é a **única** que conhece o vocabulário do Asaas. Ela traduz nos
 * dois sentidos e nunca deixa `billingType`, `walletId` ou status do gateway
 * atravessar para `Domain/`.
 *
 * ⚠️ Não usar em produção antes de fechar as sete lacunas de `docs/asaas.md` §8.
 */
final readonly class AsaasPaymentProvider implements PaymentProviderInterface
{
    public function __construct(
        private HttpFactory $http,
        private string $baseUrl,
        private string $apiKey,
        /** Carteira da plataforma, destino do split da taxa (ADR 0009 §1). */
        private string $platformWalletId,
        /** Token compartilhado do webhook, comparado em tempo constante. */
        private string $webhookToken,
        private int $timeoutSeconds = 20,
    ) {}

    public function name(): string
    {
        return 'asaas';
    }

    public function createCharge(ChargeRequest $request): ChargeSnapshot
    {
        $this->assertConfigured();

        /*
         * Split em `fixedValue`, NUNCA `percentualValue`.
         *
         * O percentual do Asaas incide sobre o `netValue` (bruto − taxa do
         * gateway). Como a nossa taxa é definida sobre o bruto (§7.7), usar
         * percentual faria a receita do SaaS variar com a taxa do gateway, que
         * muda por método. O valor já vem calculado pelo domínio (ADR 0009 §1).
         */
        $body = [
            'billingType' => $this->billingTypeFor($request->method),
            'value' => $this->toReais($request->gross),
            'dueDate' => $request->dueDate->format('Y-m-d'),
            'description' => $request->description,
            // Elo entre a cobrança do gateway e a inscrição (CLAUDE.md §28).
            'externalReference' => $request->externalReference,
        ];

        if ($request->platformFee->isPositive()) {
            $body['split'] = [[
                'walletId' => $this->platformWalletId,
                'fixedValue' => $this->toReais($request->platformFee),
            ]];
        }

        $response = $this->send('POST', '/v3/payments', $body);

        if (! $response->successful()) {
            throw PaymentProviderException::chargeFailed($this->name(), $this->errorDetail($response));
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        $snapshot = $this->toSnapshot($data);

        /*
         * O QR Code do PIX vem de endpoint separado. Falha aqui NÃO derruba a
         * cobrança: ela já existe no gateway, e desfazer por causa do QR
         * deixaria cobrança órfã. O atleta usa o `checkoutUrl`, e a
         * reconciliação preenche o resto depois.
         */
        if ($request->method === PaymentMethod::PIX && $snapshot->providerPaymentId !== '') {
            $snapshot = $this->attachPixQrCode($snapshot);
        }

        return $snapshot;
    }

    public function fetchCharge(string $providerPaymentId): ChargeSnapshot
    {
        $this->assertConfigured();

        $response = $this->send('GET', '/v3/payments/'.urlencode($providerPaymentId));

        if (! $response->successful()) {
            throw PaymentProviderException::fetchFailed(
                $this->name(),
                $providerPaymentId,
                $this->errorDetail($response),
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        return $this->toSnapshot($data);
    }

    public function refundCharge(string $providerPaymentId, ?Money $amount, string $reason): ChargeSnapshot
    {
        $this->assertConfigured();

        $body = ['description' => $reason];

        // Ausência de `value` = estorno total (docs/asaas.md §5).
        if ($amount !== null) {
            $body['value'] = $this->toReais($amount);
        }

        $response = $this->send('POST', '/v3/payments/'.urlencode($providerPaymentId).'/refund', $body);

        if (! $response->successful()) {
            throw PaymentProviderException::refundFailed(
                $this->name(),
                $providerPaymentId,
                $this->errorDetail($response),
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        return $this->toSnapshot($data);
    }

    /**
     * Autenticidade do webhook.
     *
     * O Asaas envia um **token compartilhado** no header `asaas-access-token` —
     * não é assinatura HMAC do corpo, então o corpo não entra na comparação.
     *
     * `hash_equals` para comparação em tempo constante: comparar com `===`
     * vazaria o token por timing. E o token nunca é logado (§21).
     *
     * @param  array<string, string|array<int, string|null>|null>  $headers
     */
    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        if ($this->webhookToken === '') {
            // Sem segredo configurado, nada é aceito. Falhar fechado.
            return false;
        }

        $received = $this->headerValue($headers, 'asaas-access-token');

        if ($received === null) {
            return false;
        }

        return hash_equals($this->webhookToken, $received);
    }

    /** @param  array<string, mixed>  $payload */
    public function parseWebhook(array $payload): ?WebhookEvent
    {
        $eventId = $payload['id'] ?? null;
        $eventType = $payload['event'] ?? null;

        // Sem id de evento não há como garantir idempotência: não é evento
        // reconhecível (docs/asaas.md §4).
        if (! is_string($eventId) || $eventId === '' || ! is_string($eventType)) {
            return null;
        }

        /** @var array<string, mixed> $charge */
        $charge = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];

        return new WebhookEvent(
            providerEventId: $eventId,
            providerEventType: $eventType,
            providerPaymentId: is_string($charge['id'] ?? null) ? $charge['id'] : null,
            externalReference: is_string($charge['externalReference'] ?? null)
                ? $charge['externalReference']
                : null,
            resultingStatus: $this->statusForEvent($eventType),
            gatewayFee: $this->gatewayFeeFrom($charge),
            refundedTotal: $this->moneyFrom($charge['refundedValue'] ?? null),
            occurredAt: $this->dateFrom($payload['dateCreated'] ?? null),
        );
    }

    /* ------------------------------------------------------------------ *
     * Tradução Asaas → domínio
     * ------------------------------------------------------------------ */

    /**
     * Evento de webhook → status do domínio.
     *
     * `null` = evento reconhecido **sem efeito** aqui. O Asaas manda 20+ tipos
     * (docs/asaas.md §4) e a maioria não muda nada nosso; devolver null leva ao
     * caminho `IGNORED`, que é diferente de falha.
     *
     * `PAYMENT_CONFIRMED` e `PAYMENT_RECEIVED` são mapeados para estados
     * **distintos** de propósito — é a decisão central do ADR 0009 §2.
     */
    private function statusForEvent(string $eventType): ?PaymentStatus
    {
        return match ($eventType) {
            'PAYMENT_CONFIRMED' => PaymentStatus::CONFIRMED,

            // Dinheiro disponível na conta. `RECEIVED_IN_CASH` é recebimento
            // fora do gateway e vale o mesmo para a inscrição.
            'PAYMENT_RECEIVED',
            'PAYMENT_RECEIVED_IN_CASH' => PaymentStatus::RECEIVED,

            'PAYMENT_OVERDUE' => PaymentStatus::OVERDUE,

            'PAYMENT_REFUNDED' => PaymentStatus::REFUNDED,
            'PAYMENT_PARTIALLY_REFUNDED' => PaymentStatus::PARTIALLY_REFUNDED,

            'PAYMENT_CHARGEBACK_REQUESTED' => PaymentStatus::CHARGEBACK,

            'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED',
            'PAYMENT_REPROVED_BY_RISK_ANALYSIS' => PaymentStatus::FAILED,

            'PAYMENT_DELETED' => PaymentStatus::CANCELLED,

            /*
             * Reconhecidos e sem efeito. `PAYMENT_REFUND_IN_PROGRESS` entra aqui
             * de propósito: a doc é explícita que o estorno só está concluído
             * quando o refund fica `DONE` — antecipar marcaria como estornado
             * dinheiro que ainda não voltou.
             */
            'PAYMENT_CREATED',
            'PAYMENT_UPDATED',
            'PAYMENT_AUTHORIZED',
            'PAYMENT_AWAITING_RISK_ANALYSIS',
            'PAYMENT_APPROVED_BY_RISK_ANALYSIS',
            'PAYMENT_ANTICIPATED',
            'PAYMENT_REFUND_IN_PROGRESS',
            'PAYMENT_REFUND_DENIED',
            'PAYMENT_CHARGEBACK_DISPUTE',
            'PAYMENT_AWAITING_CHARGEBACK_REVERSAL',
            'PAYMENT_DUNNING_REQUESTED',
            'PAYMENT_DUNNING_RECEIVED',
            'PAYMENT_RESTORED',
            'PAYMENT_BANK_SLIP_VIEWED',
            'PAYMENT_CHECKOUT_VIEWED' => null,

            default => null,
        };
    }

    /** Status da cobrança (consulta) → status do domínio. */
    private function statusForCharge(?string $asaasStatus): PaymentStatus
    {
        return match ($asaasStatus) {
            'CONFIRMED' => PaymentStatus::CONFIRMED,
            'RECEIVED', 'RECEIVED_IN_CASH', 'DUNNING_RECEIVED' => PaymentStatus::RECEIVED,
            'OVERDUE' => PaymentStatus::OVERDUE,
            'REFUNDED' => PaymentStatus::REFUNDED,
            'REFUND_REQUESTED', 'REFUND_IN_PROGRESS' => PaymentStatus::PARTIALLY_REFUNDED,
            'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE', 'AWAITING_CHARGEBACK_REVERSAL' => PaymentStatus::CHARGEBACK,
            'AWAITING_RISK_ANALYSIS' => PaymentStatus::PENDING,
            default => PaymentStatus::PENDING,
        };
    }

    /** @param  array<string, mixed>  $data */
    private function toSnapshot(array $data): ChargeSnapshot
    {
        $status = $this->statusForCharge(is_string($data['status'] ?? null) ? $data['status'] : null);

        return new ChargeSnapshot(
            providerPaymentId: is_string($data['id'] ?? null) ? $data['id'] : '',
            status: $status,
            gross: $this->moneyFrom($data['value'] ?? null) ?? Money::zero(),
            gatewayFee: $this->gatewayFeeFrom($data),
            confirmedAt: $this->dateFrom($data['confirmedDate'] ?? null),
            receivedAt: $this->dateFrom($data['paymentDate'] ?? null),
            checkoutUrl: is_string($data['invoiceUrl'] ?? null) ? $data['invoiceUrl'] : null,
            refundedTotal: $this->moneyFrom($data['refundedValue'] ?? null),
        );
    }

    /**
     * Taxa do gateway = `value − netValue` (ADR 0009 §5).
     *
     * `null` quando o Asaas não informou `netValue`. Nunca heurística — ADR 0004
     * proíbe reproduzir "PIX R$ 1,99; cartão 2,99% + R$ 0,39" como se fosse
     * verdade.
     *
     * @param  array<string, mixed>  $charge
     */
    private function gatewayFeeFrom(array $charge): ?Money
    {
        $gross = $this->moneyFrom($charge['value'] ?? null);
        $net = $this->moneyFrom($charge['netValue'] ?? null);

        if ($gross === null || $net === null) {
            return null;
        }

        $fee = $gross->subtract($net);

        // Líquido maior que o bruto não faz sentido: prefere desconhecido a
        // gravar taxa negativa.
        return $fee->isNegative() ? null : $fee;
    }

    private function attachPixQrCode(ChargeSnapshot $snapshot): ChargeSnapshot
    {
        try {
            $response = $this->send(
                'GET',
                '/v3/payments/'.urlencode($snapshot->providerPaymentId).'/pixQrCode',
            );

            if (! $response->successful()) {
                return $snapshot;
            }

            /** @var array<string, mixed> $qr */
            $qr = $response->json();

            return new ChargeSnapshot(
                providerPaymentId: $snapshot->providerPaymentId,
                status: $snapshot->status,
                gross: $snapshot->gross,
                gatewayFee: $snapshot->gatewayFee,
                confirmedAt: $snapshot->confirmedAt,
                receivedAt: $snapshot->receivedAt,
                checkoutUrl: $snapshot->checkoutUrl,
                pixPayload: is_string($qr['payload'] ?? null) ? $qr['payload'] : null,
                pixQrCodeBase64: is_string($qr['encodedImage'] ?? null) ? $qr['encodedImage'] : null,
                refundedTotal: $snapshot->refundedTotal,
            );
        } catch (Throwable) {
            // A cobrança existe: não desfazer por causa do QR.
            return $snapshot;
        }
    }

    /* ------------------------------------------------------------------ *
     * HTTP
     * ------------------------------------------------------------------ */

    /** @param  array<string, mixed>|null  $body */
    private function send(string $method, string $path, ?array $body = null): Response
    {
        try {
            $request = $this->http
                ->baseUrl($this->baseUrl)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                // A doc especifica o header `access_token`.
                ->withHeaders(['access_token' => $this->apiKey]);

            return $method === 'GET'
                ? $request->get($path)
                : $request->post($path, $body ?? []);
        } catch (ConnectionException $e) {
            throw PaymentProviderException::chargeFailed($this->name(), 'connection: '.$e->getMessage());
        }
    }

    /**
     * Detalhe do erro para log — nunca para o cliente.
     *
     * O corpo de erro do Asaas traz `errors[].code` e `description`. A chave de
     * API não aparece aqui porque vai em header, não em corpo.
     */
    private function errorDetail(Response $response): string
    {
        /** @var array<string, mixed>|null $body */
        $body = $response->json();

        if (is_array($body) && is_array($body['errors'] ?? null)) {
            $parts = [];

            foreach ($body['errors'] as $error) {
                if (is_array($error)) {
                    $parts[] = trim(
                        (is_string($error['code'] ?? null) ? $error['code'] : '')
                        .' '
                        .(is_string($error['description'] ?? null) ? $error['description'] : '')
                    );
                }
            }

            if ($parts !== []) {
                return 'HTTP '.$response->status().': '.implode(' | ', $parts);
            }
        }

        return 'HTTP '.$response->status();
    }

    private function assertConfigured(): void
    {
        if ($this->apiKey === '') {
            throw PaymentProviderException::misconfigured($this->name(), 'api_key');
        }

        if ($this->baseUrl === '') {
            throw PaymentProviderException::misconfigured($this->name(), 'base_url');
        }

        if ($this->platformWalletId === '') {
            throw PaymentProviderException::misconfigured($this->name(), 'platform_wallet_id');
        }
    }

    /* ------------------------------------------------------------------ *
     * Conversões
     * ------------------------------------------------------------------ */

    private function billingTypeFor(PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::PIX => 'PIX',
            PaymentMethod::CREDIT_CARD => 'CREDIT_CARD',
            PaymentMethod::BOLETO => 'BOLETO',
        };
    }

    /**
     * Centavos → reais para o corpo da requisição.
     *
     * A API do Asaas trabalha em reais decimais; nós, em centavos (§7.1). A
     * conversão passa por **string**, via `Money::toReais()`, e nunca por float:
     * `13000 / 100` em float é o caminho para pagar R$ 129,99.
     */
    private function toReais(Money $money): float
    {
        return (float) $money->toReais();
    }

    /**
     * Reais do Asaas → `Money` em centavos.
     *
     * Converte a partir da **representação em string**, com `Money::fromReais`,
     * para não herdar erro de ponto flutuante do JSON.
     */
    private function moneyFrom(mixed $value): ?Money
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return Money::fromReais((string) $value);
        }

        if (is_float($value)) {
            return Money::fromReais(number_format($value, 2, '.', ''));
        }

        if (is_string($value) && $value !== '') {
            return Money::fromReais($value);
        }

        return null;
    }

    private function dateFrom(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Header case-insensitive.
     *
     * O array de headers do Laravel vem com chave minúscula e valor em array,
     * mas um proxy pode normalizar diferente — daí a busca tolerante.
     *
     * @param  array<string, string|array<int, string|null>|null>  $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (mb_strtolower($key) !== $name) {
                continue;
            }

            if (is_array($value)) {
                $first = $value[0] ?? null;

                return is_string($first) ? $first : null;
            }

            return is_string($value) ? $value : null;
        }

        return null;
    }
}
