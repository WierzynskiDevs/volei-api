<?php

declare(strict_types=1);

use App\Modules\Events\Domain\Enums\RegistrationFieldKey;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Campos de inscrição configuráveis pelo organizador (Q19, ADR 0016).
 *
 * Catálogo fechado — cobre exatamente o que CLAUDE.md §11 exige: allowlist
 * real, não texto livre. `eventPayload()` local para não acoplar a
 * `OrganizerEventTest.php`, que já é grande.
 */

function eventPayloadWithFields(array $overrides = []): array
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

describe('POST /api/v1/organizer/events — campos configuráveis', function (): void {
    it('grava os campos exigidos e opcionais', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayloadWithFields([
                'required_registration_fields' => ['EMERGENCY_CONTACT', 'SHIRT_SIZE'],
                'optional_registration_fields' => ['TEAM_NAME'],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.required_registration_fields', ['EMERGENCY_CONTACT', 'SHIRT_SIZE'])
            ->assertJsonPath('data.optional_registration_fields', ['TEAM_NAME']);
    });

    it('sem campos enviados, nasce com os dois arrays vazios', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayloadWithFields())
            ->assertCreated()
            ->assertJsonPath('data.required_registration_fields', [])
            ->assertJsonPath('data.optional_registration_fields', []);
    });

    it('rejeita valor fora do catálogo fechado — 422', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayloadWithFields([
                'required_registration_fields' => ['CPF'], // não existe no enum
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    });

    it('rejeita o mesmo campo como obrigatório e opcional ao mesmo tempo — 422', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayloadWithFields([
                'required_registration_fields' => ['SHIRT_SIZE'],
                'optional_registration_fields' => ['SHIRT_SIZE'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.optional_registration_fields.0', fn (string $msg) => str_contains(
                $msg,
                'SHIRT_SIZE',
            ));
    });
});

describe('PATCH /api/v1/organizer/events/{slug} — campos configuráveis', function (): void {
    it('atualiza os campos configurados', function (): void {
        $event = Event::factory()->forOrganizer($this->organizer)->create([
            'required_registration_fields' => ['SHIRT_SIZE'],
            'optional_registration_fields' => [],
        ]);

        $this->actingAs($this->organizerUser)
            ->patchJson("/api/v1/organizer/events/{$event->slug}", eventPayloadWithFields([
                'name' => $event->name,
                'required_registration_fields' => ['TEAM_NAME'],
                'optional_registration_fields' => ['DIETARY_RESTRICTION'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.required_registration_fields', ['TEAM_NAME'])
            ->assertJsonPath('data.optional_registration_fields', ['DIETARY_RESTRICTION']);
    });
});

describe('Event — helpers de domínio', function (): void {
    it('collectsRegistrationField é verdadeiro tanto para exigido quanto para opcional', function (): void {
        $event = Event::factory()->create([
            'required_registration_fields' => ['SHIRT_SIZE'],
            'optional_registration_fields' => ['TEAM_NAME'],
        ]);

        expect($event->collectsRegistrationField(RegistrationFieldKey::SHIRT_SIZE))
            ->toBeTrue()
            ->and($event->collectsRegistrationField(RegistrationFieldKey::TEAM_NAME))
            ->toBeTrue()
            ->and($event->collectsRegistrationField(
                RegistrationFieldKey::DIETARY_RESTRICTION
            ))->toBeFalse();
    });
});
