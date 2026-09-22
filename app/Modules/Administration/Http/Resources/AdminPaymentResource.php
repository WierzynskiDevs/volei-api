<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Resources;

use App\Modules\Payments\Infrastructure\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cobrança na visão administrativa.
 *
 * Todo valor é inteiro em centavos, com sufixo `_cents` (CLAUDE.md §13).
 * Nenhum é calculado aqui: são os valores **congelados** na criação da cobrança
 * (§7.5). Este Resource não recalcula taxa nem líquido — recalcular pagamento
 * passado é proibido.
 *
 * `asaas_fee_cents` e `organizer_net_cents` podem ser `null`, e `null` significa
 * *desconhecido*, não zero (ADR 0009 §5): a taxa do gateway só existe depois que
 * o Asaas responde. A tela mostra "indisponível" — zero afirmaria que o
 * organizador não recebe nada.
 *
 * @mixin Payment
 */
final class AdminPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'method' => $this->method->value,
            'method_label' => $this->method->label(),

            'provider' => $this->provider,
            'external_reference' => $this->external_reference,

            'gross_cents' => $this->gross_cents,
            'platform_fee_cents' => $this->platform_fee_cents,
            'platform_fee_basis_points' => $this->platform_fee_basis_points,
            'asaas_fee_cents' => $this->asaas_fee_cents,
            'organizer_net_cents' => $this->organizer_net_cents,
            'refunded_cents' => $this->refunded_cents,

            'due_at' => $this->due_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),

            /*
             * Quem pagou: nome e e-mail. Sem telefone — a coluna
             * "Participante" da tela mostra nome, e o e-mail é o que permite
             * ao suporte encontrar a pessoa. Telefone não serve a nenhum dos
             * dois usos (§12, minimização).
             */
            'payer' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),

            'event' => $this->whenLoaded('event', fn (): ?array => $this->event === null ? null : [
                'id' => $this->event->id,
                'slug' => $this->event->slug,
                'name' => $this->event->name,
            ]),

            'organizer' => $this->whenLoaded('organizer', fn (): ?array => $this->organizer === null ? null : [
                'id' => $this->organizer->id,
                'name' => $this->organizer->name,
            ]),

            'registration_id' => $this->registration_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
