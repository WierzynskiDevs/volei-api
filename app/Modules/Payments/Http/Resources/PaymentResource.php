<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Infrastructure\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representação de uma cobrança.
 *
 * Dinheiro sempre em **centavos**, com sufixo `_cents` (CLAUDE.md §13). O
 * frontend formata; não calcula.
 *
 * `organizer_net_cents` é `null` enquanto a taxa do gateway não é conhecida
 * (ADR 0009 §5) — e `null` significa "indisponível", não zero. A tela precisa
 * distinguir os dois, senão mostra ao organizador que ele não recebe nada.
 *
 * @mixin Payment
 */
final class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'registration_id' => $this->registration_id,
            'event_id' => $this->event_id,

            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // Dinheiro: inteiro em centavos, sempre.
            'gross_cents' => $this->gross_cents,
            'platform_fee_cents' => $this->platform_fee_cents,
            'asaas_fee_cents' => $this->asaas_fee_cents,
            'organizer_net_cents' => $this->organizer_net_cents,
            'refunded_cents' => $this->refunded_cents,

            /*
             * Alíquota congelada, exposta para que a tela do organizador possa
             * mostrar "taxa de 5%" sem consultar o plano atual — que pode já ter
             * mudado (§7.5).
             */
            'platform_fee_basis_points' => $this->platform_fee_basis_points,

            /*
             * `money_is_available` responde a pergunta que o dashboard faz, e
             * responde com a regra do domínio em vez de deixar a tela deduzir de
             * `status` — deduzir erraria, porque `CONFIRMED` parece "pago".
             */
            'money_is_available' => $this->status->moneyIsAvailable(),
            'confirms_registration' => $this->status->confirmsRegistration(),
            'estimated_settlement_days' => $this->method->estimatedSettlementDays(),

            'checkout_url' => $this->checkout_url,
            'pix_payload' => $this->pix_payload,

            'due_at' => $this->due_at->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
