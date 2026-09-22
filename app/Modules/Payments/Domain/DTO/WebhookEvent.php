<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\DTO;

use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;

/**
 * Evento de webhook já normalizado.
 *
 * `providerEventId` é o `id` do evento no gateway — a doc do Asaas recomenda
 * exatamente ele como chave de idempotência, e é ele que vai no unique
 * `(provider, provider_event_id)` (docs/asaas.md §4).
 *
 * `resultingStatus` é `null` para evento reconhecido que **não** muda estado
 * (`PAYMENT_BANK_SLIP_VIEWED`, por exemplo). Isso é diferente de evento
 * desconhecido: um vira `IGNORED`, o outro vira `FAILED` e pede atenção humana.
 */
final readonly class WebhookEvent
{
    public function __construct(
        public string $providerEventId,
        /** Tipo bruto do gateway. Guardado só para trilha e diagnóstico. */
        public string $providerEventType,
        public ?string $providerPaymentId,
        /** Nosso id, quando o gateway devolve o `externalReference`. */
        public ?string $externalReference,
        public ?PaymentStatus $resultingStatus,
        public ?Money $gatewayFee = null,
        public ?Money $refundedTotal = null,
        public ?CarbonImmutable $occurredAt = null,
    ) {}

    /** Evento reconhecido, mas sem efeito no domínio. */
    public function isNoOp(): bool
    {
        return $this->resultingStatus === null;
    }
}
