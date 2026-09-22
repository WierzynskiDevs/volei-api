<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Resources;

use App\Modules\Events\Infrastructure\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Evento na visão administrativa.
 *
 * Enxuto de propósito: a tela `/admin/eventos` é uma lista de governança —
 * evento, organizador, local, data, status e ação. Não é a página do evento, e
 * devolver o payload inteiro aqui seria carregar campo que ninguém lê (§26).
 *
 * Traz dois campos que o recurso público jamais traz: `cancellation_reason` e o
 * organizador com id — governança precisa saber quem respondeu pelo evento e
 * por quê ele caiu.
 *
 * @mixin Event
 */
final class AdminEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'venue_name' => $this->venue_name,
            'city' => $this->city,
            'state' => $this->state,

            // ISO 8601 com timezone, sempre (CLAUDE.md §13).
            'start_at' => $this->start_at->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,

            'registration_fee_cents' => $this->registration_fee_cents,
            'max_teams' => $this->max_teams,
            'teams_registered_count' => $this->teams_registered_count,

            'organizer' => $this->whenLoaded('organizer', fn (): ?array => $this->organizer === null ? null : [
                'id' => $this->organizer->id,
                'name' => $this->organizer->name,
                'status' => $this->organizer->status->value,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
