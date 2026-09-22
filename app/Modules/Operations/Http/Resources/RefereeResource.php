<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Resources;

use App\Modules\Operations\Infrastructure\Models\EventReferee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventReferee
 *
 * Telefone em claro (não mascarado): quem vê este Resource já passou pela
 * Policy do evento (o próprio organizador que cadastrou o juiz) — mascarar
 * aqui não protegeria nada além do que a autorização já protege
 * (CLAUDE.md §12, mesmo raciocínio de `emergency_contact_phone` na ADR 0016).
 */
final class RefereeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone_encrypted,
            'invite_status' => $this->invite_status->value,
            'invite_status_label' => $this->invite_status->label(),
            'court_id' => $this->court_id,
            'invited_at' => $this->invited_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
        ];
    }
}
