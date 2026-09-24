<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Http\Resources;

use App\Modules\Brackets\Application\DTO\PublicBracketView;
use App\Modules\Brackets\Infrastructure\Models\BracketSlot;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Chave pública do evento (ADR 0017, Q15).
 *
 * Allowlist deliberadamente mais estrita que `BracketResource` (organizador,
 * autenticado): nunca sobrenome, nunca lista de membros da dupla, nunca dado
 * de contato. `CLAUDE.md` §12/§13 — o backend decide o que é público, o
 * cliente não mascara.
 *
 * @mixin PublicBracketView
 */
final class PublicBracketResource extends JsonResource
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
            'display_name' => $group->publicDisplayName(),
        ];
    }
}
