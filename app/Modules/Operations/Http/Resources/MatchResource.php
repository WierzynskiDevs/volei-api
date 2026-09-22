<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Resources;

use App\Modules\Operations\Infrastructure\Models\GameMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GameMatch
 *
 * `*_name` só aparece quando a relação vem carregada (`whenLoaded`) — o
 * controller decide o que carregar, o Resource nunca dispara lazy load
 * (`preventLazyLoading` ativo, CLAUDE.md §5).
 */
final class MatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'phase' => $this->phase,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'court_id' => $this->court_id,
            'court_label' => $this->whenLoaded('court', fn () => $this->court?->label),
            'referee_id' => $this->referee_id,
            'referee_name' => $this->whenLoaded('referee', fn () => $this->referee?->name),
            'team_a_id' => $this->team_a_id,
            'team_a_name' => $this->whenLoaded('teamA', fn () => $this->teamA?->displayName()),
            'team_b_id' => $this->team_b_id,
            'team_b_name' => $this->whenLoaded('teamB', fn () => $this->teamB?->displayName()),
            'sets' => MatchSetResource::collection($this->whenLoaded('sets')),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
