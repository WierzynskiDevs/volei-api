<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representação de um evento na API.
 *
 * CLAUDE.md §13: nunca retornar o model direto. A lista de campos é explícita
 * para que uma coluna nova não vaze sem revisão.
 *
 * Duas regras de contrato que este Resource carrega:
 *
 *  - **Datas em ISO 8601 com offset, no fuso do evento** (ADR 0006 §5).
 *    `"2026-08-22T08:30:00-03:00"`, não `"22 ago · sáb · 08h30"`. O rótulo é
 *    apresentação e é formatado no cliente, com o fuso do evento — nunca com o
 *    do navegador.
 *
 *  - **Dinheiro em centavos, com sufixo `_cents`**. Nenhum valor formatado
 *    trafega: `"R$ 120 / dupla"` era exatamente a fonte da contradição do
 *    baseline (docs/DIVERGENCES.md §5).
 *
 * Este recurso é **público**: é ele que serve a página aberta do evento. Nada
 * de dado pessoal do organizador entra aqui — nem e-mail, nem telefone
 * (CLAUDE.md §12).
 *
 * @mixin Event
 */
final class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,

            'organizer' => $this->whenLoaded('organizer', fn (): ?array => $this->organizer instanceof Organizer
                ? [
                    'id' => $this->organizer->id,
                    'name' => $this->organizer->name,
                    'slug' => $this->organizer->slug,
                ]
                : null),

            'venue_name' => $this->venue_name,
            'city' => $this->city,
            'state' => $this->state,

            // Fuso do evento junto: o cliente precisa dele para formatar sem
            // depender do relógio de quem está olhando.
            'timezone' => $this->timezone,
            'start_at' => $this->inEventTimezone($this->start_at),
            'end_at' => $this->inEventTimezone($this->end_at),
            'registration_open_at' => $this->inEventTimezone($this->registration_open_at),
            'registration_close_at' => $this->inEventTimezone($this->registration_close_at),

            'registration_fee_cents' => $this->registration_fee_cents,

            // null = sem teto. Diferente de 0, e a tela precisa distinguir.
            'max_teams' => $this->max_teams,
            'teams_registered_count' => $this->teams_registered_count,
            'remaining_team_slots' => $this->remainingTeamSlots(),

            'courts' => $this->courts,
            'min_games' => $this->min_games,
            'format' => $this->format,

            'modality' => $this->modality->value,
            'gender_category' => $this->gender_category->value,
            'level_category' => $this->level_category->value,
            'age_category' => $this->age_category->value,
            'event_type' => $this->event_type->value,

            'prize_description' => $this->prize_description,
            'rules' => $this->rules,

            // Q19/ADR 0016 — o que a tela de inscrição precisa saber para
            // renderizar os campos extras. Sem dado pessoal aqui: é só o
            // catálogo (chaves), nunca resposta de ninguém.
            'required_registration_fields' => $this->required_registration_fields,
            'optional_registration_fields' => $this->optional_registration_fields,

            // Configuração operacional (ADR 0013 §2, S8a) — dado inerte nesta
            // fatia, consumidor real chega em S9 (matches/match_sets).
            'days_count' => $this->days_count,
            'match_duration_min' => $this->match_duration_min,
            'best_of_sets' => $this->best_of_sets,
            'points_per_set' => $this->points_per_set,
            'tiebreak_points' => $this->tiebreak_points,
            'scoring_rules' => $this->scoring_rules,

            'status' => $this->status->value,
            'published_at' => $this->inEventTimezone($this->published_at),
            'cancelled_at' => $this->inEventTimezone($this->cancelled_at),
            'cancellation_reason' => $this->cancellation_reason,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * O instante é sempre o mesmo — muda só a representação. Enviar no fuso do
     * evento evita que o cliente precise saber converter para acertar o rótulo.
     */
    private function inEventTimezone(?DateTimeInterface $moment): ?string
    {
        if ($moment === null) {
            return null;
        }

        return CarbonImmutable::instance($moment)
            ->setTimezone($this->timezone)
            ->toIso8601String();
    }
}
