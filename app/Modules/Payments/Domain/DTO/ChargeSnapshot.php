<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\DTO;

use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;

/**
 * Estado de uma cobrança no gateway, já normalizado.
 *
 * É o que a interface devolve tanto na criação quanto na consulta de
 * reconciliação. O status já vem traduzido para o `PaymentStatus` do domínio.
 */
final readonly class ChargeSnapshot
{
    public function __construct(
        public string $providerPaymentId,
        public PaymentStatus $status,
        public Money $gross,
        /**
         * Taxa cobrada pelo gateway, derivada de `gross − netValue`.
         *
         * `null` enquanto o gateway não informa. Nunca heurística
         * (ADR 0004, ADR 0009 §5).
         */
        public ?Money $gatewayFee,
        public ?CarbonImmutable $confirmedAt,
        public ?CarbonImmutable $receivedAt,
        /** Link de checkout / QR PIX, quando houver. */
        public ?string $checkoutUrl = null,
        public ?string $pixPayload = null,
        public ?string $pixQrCodeBase64 = null,
        /** Total já estornado, para distinguir estorno parcial de total. */
        public ?Money $refundedTotal = null,
    ) {}
}
