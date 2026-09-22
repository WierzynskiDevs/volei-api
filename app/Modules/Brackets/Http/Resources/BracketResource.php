<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Http\Resources;

use App\Modules\Brackets\Application\DTO\BracketView;
use App\Modules\Brackets\Infrastructure\Models\BracketSlot;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representação da chave de um evento (ADR 0011).
 *
 * CLAUDE.md §13/§12: nunca o model direto, e nunca e-mail/telefone dos
 * membros da dupla — só o nome, que é o que a tela de sorteio precisa mostrar.
 *
 * @mixin BracketView
 */
final class BracketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'event_status' => $this->event->status->value,
            'bracket_size' => $this->slots->count(),
            'slots' => $this->slots
                ->map(fn (BracketSlot $slot): array => [
                    'position' => $slot->position,
                    'is_bye' => $slot->is_bye,
                    'registration_group' => $this->groupPayload($slot->registrationGroup),
                ])
                ->values()
                ->all(),
            'eligible_groups' => $this->eligibleGroups
                ->map(fn (RegistrationGroup $group): ?array => $this->groupPayload($group))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function groupPayload(?RegistrationGroup $group): ?array
    {
        if (! $group instanceof RegistrationGroup) {
            return null;
        }

        return [
            'id' => $group->id,
            'display_name' => $group->displayName(),
            'members' => $group->relationLoaded('registrations')
                ? $group->registrations
                    ->map(fn (Registration $r): ?string => $r->relationLoaded('user') ? $r->user?->name : null)
                    ->filter()
                    ->values()
                    ->all()
                : [],
        ];
    }
}
