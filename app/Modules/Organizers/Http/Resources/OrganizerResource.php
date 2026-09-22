<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Http\Resources;

use App\Modules\Organizers\Infrastructure\Models\Organizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Organizer
 *
 * Proibido retornar o model direto (CLAUDE.md §13) — documento e telefone,
 * mesmo já `$hidden` no model, não têm por que trafegar nem mascarados aqui:
 * quem acabou de criar o próprio perfil não precisa que a resposta ecoe de
 * volta o CPF que acabou de digitar.
 */
final class OrganizerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_account_status' => $this->payment_account_status->value,
            'payment_account_status_label' => $this->payment_account_status->label(),
            'can_receive_payments' => $this->canReceivePayments(),
            'plan_code' => $this->whenLoaded('plan', fn (): ?string => $this->plan?->code),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
