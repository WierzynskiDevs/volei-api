<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Resources;

use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Partida pública do evento (ADR 0017, Q15).
 *
 * Allowlist deliberadamente mais estrita que `MatchResource` (organizador,
 * autenticado): nunca juiz (nome ou id) — a ADR 0017 não inclui árbitro no
 * que é público — e nunca `event_id`/`referee_id` crus. Reaproveita
 * `MatchSetResource` (já seguro: só números do placar).
 *
 * @mixin GameMatch
 */
final class PublicMatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phase' => $this->phase,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'court_label' => $this->whenLoaded('court', fn () => $this->court?->label),
            'team_a_name' => $this->whenLoaded('teamA', fn () => $this->publicName($this->teamA)),
            'team_b_name' => $this->whenLoaded('teamB', fn () => $this->publicName($this->teamB)),
            'sets' => MatchSetResource::collection($this->whenLoaded('sets')),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }

    private function publicName(?RegistrationGroup $group): ?string
    {
        return $group?->publicDisplayName();
    }
}
