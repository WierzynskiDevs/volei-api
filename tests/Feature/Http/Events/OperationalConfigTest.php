<?php

declare(strict_types=1);

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Configuração operacional do evento — quadras, duração de partida, sets,
 * pontuação (ADR 0013 §2, S8a).
 */

function eventPayloadWithOperationalFields(array $overrides = []): array
{
    return array_merge([
        'name' => 'Copa Areia Curitiba',
        'venue_name' => 'Arena Norte Beach',
        'city' => 'Curitiba',
        'state' => 'PR',
        'date' => '2026-11-14',
        'start_time' => '08:30',
        'end_time' => '18:00',
        'registration_fee_cents' => 13000,
        'courts' => 4,
        'min_games' => 3,
        'modality' => 'TWO_VS_TWO',
        'gender_category' => 'MALE',
        'level_category' => 'ADVANCED',
        'event_type' => 'RANKING',
    ], $overrides);
}

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create();
});

describe('POST /api/v1/organizer/events — configuração operacional', function (): void {
    it('grava os campos operacionais enviados', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayloadWithOperationalFields([
                'days_count' => 2,
                'match_duration_min' => 40,
                'best_of_sets' => 5,
                'points_per_set' => 25,
                'tiebreak_points' => 17,
                'scoring_rules' => [
                    'win' => 3, 'loss' => 0, 'champion' => 12,
                    'runnerUp' => 8, 'third' => 4, 'participation' => 1,
                ],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.days_count', 2)
            ->assertJsonPath('data.match_duration_min', 40)
            ->assertJsonPath('data.best_of_sets', 5)
            ->assertJsonPath('data.points_per_set', 25)
            ->assertJsonPath('data.tiebreak_points', 17)
            ->assertJsonPath('data.scoring_rules.champion', 12);
    });

    it('sem campos enviados, usa os defaults da plataforma (ADR 0008)', function (): void {
        $response = $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayloadWithOperationalFields())
            ->assertCreated()
            ->assertJsonPath('data.days_count', 1)
            ->assertJsonPath('data.match_duration_min', 35)
            ->assertJsonPath('data.best_of_sets', 3);

        // Postgres jsonb não preserva ordem de inserção das chaves — compara
        // por valor, não pela serialização exata do array.
        expect($response->json('data.scoring_rules'))->toEqual([
            'win' => 2, 'loss' => -1, 'champion' => 10,
            'runnerUp' => 6, 'third' => 3, 'participation' => 1,
        ]);
    });

    it('rejeita best_of_sets fora de {1,3,5}', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayloadWithOperationalFields(['best_of_sets' => 4]))
            ->assertStatus(422);
    });

    it('rejeita scoring_rules com chave faltando', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayloadWithOperationalFields([
                'scoring_rules' => ['win' => 2, 'loss' => -1], // faltam as outras 4 chaves
            ]))
            ->assertStatus(422);
    });
});

describe('PATCH /api/v1/organizer/events/{slug} — edição parcial não reseta configuração', function (): void {
    it('omitir days_count na edição PRESERVA o valor já gravado', function (): void {
        $event = Event::factory()->forOrganizer($this->organizer)->create([
            'days_count' => 3,
            'best_of_sets' => 5,
        ]);

        $this->actingAs($this->organizerUser)
            ->patchJson("/api/v1/organizer/events/{$event->slug}", eventPayloadWithOperationalFields([
                'name' => $event->name,
                // days_count e best_of_sets propositalmente omitidos
            ]))
            ->assertOk()
            ->assertJsonPath('data.days_count', 3)
            ->assertJsonPath('data.best_of_sets', 5);
    });

    it('enviar days_count na edição SUBSTITUI o valor gravado', function (): void {
        $event = Event::factory()->forOrganizer($this->organizer)->create(['days_count' => 1]);

        $this->actingAs($this->organizerUser)
            ->patchJson("/api/v1/organizer/events/{$event->slug}", eventPayloadWithOperationalFields([
                'name' => $event->name,
                'days_count' => 4,
            ]))
            ->assertOk()
            ->assertJsonPath('data.days_count', 4);
    });

    it('omitir required_registration_fields na edição também preserva (Q19)', function (): void {
        $event = Event::factory()->forOrganizer($this->organizer)->create([
            'required_registration_fields' => ['SHIRT_SIZE'],
        ]);

        $this->actingAs($this->organizerUser)
            ->patchJson("/api/v1/organizer/events/{$event->slug}", eventPayloadWithOperationalFields([
                'name' => $event->name,
            ]))
            ->assertOk()
            ->assertJsonPath('data.required_registration_fields', ['SHIRT_SIZE']);
    });
});
