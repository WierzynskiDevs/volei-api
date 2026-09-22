<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\DTO\WebhookEvent;
use App\Modules\Payments\Domain\Enums\WebhookProcessingStatus;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Payments\Infrastructure\Models\PaymentWebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persiste o evento de webhook **antes** de processar (CLAUDE.md §14).
 *
 * Esta é a ordem que a própria doc do Asaas recomenda: gravar numa tabela com
 * unique no id do evento e só então responder 200, processando depois de forma
 * assíncrona (docs/asaas.md §4).
 *
 * ## Reentrega
 *
 * A entrega é "at least once" — o mesmo evento chega repetido. O unique em
 * `(provider, provider_event_id)` recusa o segundo insert, e o resultado é "já
 * registrado": o controller responde 200 e nada é processado de novo. Absorver
 * com 200 é obrigatório; responder erro penalizaria a fila do gateway (15
 * falhas consecutivas podem interrompê-la).
 *
 * A absorção usa `insertOrIgnore` (`ON CONFLICT DO NOTHING`) em vez de
 * try/catch: no PostgreSQL um statement que falha **aborta a transação**
 * (`SQLSTATE 25P02`), e o `SELECT` seguinte — que busca o registro existente —
 * falharia com "current transaction is aborted".
 */
final readonly class RecordWebhookEventAction
{
    /**
     * Chaves que nunca entram na trilha (CLAUDE.md §9 e §21).
     *
     * O payload de cobrança do Asaas traz um bloco `creditCard` — número
     * mascarado, bandeira, token. Nada disso pode ser persistido: §9 é explícito
     * que a trilha não guarda dado de cartão.
     */
    private const array STRIPPED_KEYS = [
        'creditcard',
        'creditcardtoken',
        'creditcardnumber',
        'creditcardbrand',
        'ccv',
        'cvv',
        'holdername',
        'accesstoken',
        'apikey',
        'authtoken',
        'access_token',
    ];

    /**
     * @param  array<string, mixed>  $rawPayload
     * @return array{0: PaymentWebhookEvent, 1: bool} o evento e se é novo
     */
    public function execute(
        string $provider,
        WebhookEvent $event,
        array $rawPayload,
        CarbonImmutable $now,
    ): array {
        $payment = $this->resolvePayment($provider, $event);

        $affected = DB::table('payment_webhook_events')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'provider' => $provider,
            'provider_event_id' => $event->providerEventId,
            'provider_event_type' => $event->providerEventType,
            'provider_payment_id' => $event->providerPaymentId,
            'payment_id' => $payment?->id,
            'payload' => json_encode($this->sanitize($rawPayload), JSON_THROW_ON_ERROR),
            'processing_status' => WebhookProcessingStatus::PENDING->value,
            'attempts' => 0,
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var PaymentWebhookEvent $record */
        $record = PaymentWebhookEvent::query()
            ->where('provider', $provider)
            ->where('provider_event_id', $event->providerEventId)
            ->firstOrFail();

        return [$record, $affected > 0];
    }

    /**
     * Casa o evento com um pagamento nosso.
     *
     * `externalReference` primeiro — é o nosso id e o elo mais confiável
     * (CLAUDE.md §28). O `provider_payment_id` é a segunda tentativa, porque
     * existem eventos em que o Asaas não devolve a referência externa.
     *
     * `null` é resultado aceitável: o evento é gravado de todo jeito. Descartar
     * evento não casado seria descartar dinheiro.
     */
    private function resolvePayment(string $provider, WebhookEvent $event): ?Payment
    {
        if ($event->externalReference !== null && $event->externalReference !== '') {
            $byReference = Payment::query()
                ->where('external_reference', $event->externalReference)
                ->first();

            if ($byReference instanceof Payment) {
                return $byReference;
            }
        }

        if ($event->providerPaymentId !== null && $event->providerPaymentId !== '') {
            return Payment::query()
                ->where('provider', $provider)
                ->where('provider_payment_id', $event->providerPaymentId)
                ->first();
        }

        return null;
    }

    /**
     * Remove recursivamente o que não pode ser persistido.
     *
     * Comparação por fragmento em minúscula, como no `AuditLogger`: o Asaas usa
     * camelCase e uma lista exata erraria `creditCardToken`.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function sanitize(array $payload, int $depth = 0): array
    {
        if ($depth > 10) {
            return ['_truncated' => 'profundidade máxima excedida'];
        }

        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isStripped($key)) {
                $clean[$key] = '[REDACTED]';

                continue;
            }

            $clean[$key] = is_array($value) ? $this->sanitize($value, $depth + 1) : $value;
        }

        return $clean;
    }

    private function isStripped(string $key): bool
    {
        $needle = str_replace(['_', '-'], '', mb_strtolower($key));

        foreach (self::STRIPPED_KEYS as $fragment) {
            if (str_contains($needle, str_replace('_', '', $fragment))) {
                return true;
            }
        }

        return false;
    }
}
