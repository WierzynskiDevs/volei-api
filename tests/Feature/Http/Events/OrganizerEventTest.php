<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Painel do organizador — criação, edição e transições de estado.
 *
 * Cobre o exigido por CLAUDE.md §22 para todo endpoint protegido: caminho
 * feliz, validação, 401 anônimo, 403 papel errado, 403 recurso de terceiro
 * (IDOR) e 409 conflito de estado.
 */

/** Payload válido mínimo, no formato que a tela de criação envia. */
function eventPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Copa Areia Curitiba',
        'venue_name' => 'Arena Norte Beach',
        'city' => 'Curitiba',
        'state' => 'PR',
        'date' => '2026-11-14',
        'start_time' => '08:30',
        'end_time' => '18:00',
        'registration_close_at' => '2026-11-10 23:59',
        'registration_fee_cents' => 13000,
        'max_teams' => 16,
        'courts' => 4,
        'min_games' => 3,
        'format' => 'Pool Play + Gold/Silver',
        'modality' => 'TWO_VS_TWO',
        'gender_category' => 'MALE',
        'level_category' => 'ADVANCED',
        'event_type' => 'RANKING',
        'prize_description' => 'R$ 3.000 + troféus',
        'rules' => ['Melhor de 3 sets.'],
    ], $overrides);
}

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create([
        'name' => 'Arena Norte Beach',
    ]);
});

describe('POST /api/v1/organizer/events', function () {
    it('cria o evento como RASCUNHO', function () {
        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', EventStatus::DRAFT->value)
            ->assertJsonPath('data.slug', 'copa-areia-curitiba')
            ->assertJsonPath('data.registration_fee_cents', 13000)
            ->assertJsonPath('data.teams_registered_count', 0)
            ->assertJsonPath('data.organizer.id', $this->organizer->id);
    });

    it('grava a hora de parede convertida para UTC (ADR 0006)', function () {
        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayload())
            ->assertCreated()
            ->assertJsonPath('data.start_at', '2026-11-14T08:30:00-03:00');

        $event = Event::query()->firstOrFail();

        expect($event->start_at->utc()->toIso8601String())->toBe('2026-11-14T11:30:00+00:00');
    });

    it('ignora dono, estado e contagem enviados pelo cliente', function () {
        $intruder = Organizer::factory()->create();

        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayload([
                'organizer_id' => $intruder->id,
                'status' => 'REGISTRATION_OPEN',
                'teams_registered_count' => 999,
                'slug' => 'endereco-escolhido-pelo-cliente',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.organizer.id', $this->organizer->id)
            ->assertJsonPath('data.status', EventStatus::DRAFT->value)
            ->assertJsonPath('data.teams_registered_count', 0)
            ->assertJsonPath('data.slug', 'copa-areia-curitiba');
    });

    it('aceita evento sem teto de duplas', function () {
        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayload(['max_teams' => null]))
            ->assertCreated()
            ->assertJsonPath('data.max_teams', null)
            ->assertJsonPath('data.remaining_team_slots', null);
    });

    it('desempata slug repetido em vez de falhar', function () {
        $this->actingAs($this->organizerUser)->postJson('/api/v1/organizer/events', eventPayload());

        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayload())
            ->assertCreated()
            ->assertJsonPath('data.slug', 'copa-areia-curitiba-2');
    });

    it('registra na trilha de auditoria', function () {
        $this->actingAs($this->organizerUser)->postJson('/api/v1/organizer/events', eventPayload());

        $log = AuditLog::query()->where('action', AuditAction::EVENT_CREATED)->firstOrFail();

        expect($log->actor_id)->toBe($this->organizerUser->id)
            ->and($log->target_type)->toBe('event');
    });

    it('rejeita término antes do início com 422', function () {
        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayload([
                'start_time' => '18:00',
                'end_time' => '08:00',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['end_time']]]);
    });

    it('rejeita valor de inscrição negativo', function () {
        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayload(['registration_fee_cents' => -1]))
            ->assertStatus(422);
    });

    it('rejeita enum fora do conjunto', function () {
        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', eventPayload(['modality' => '3x3']))
            ->assertStatus(422);
    });

    it('respeita o limite de eventos do plano', function () {
        $organizer = Organizer::factory()->onPlan('FREE')->create();   // limite 3
        Event::factory()->count(3)->forOrganizer($organizer)->create();

        $this->actingAs($organizer->user)
            ->postJson('/api/v1/organizer/events', eventPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PLAN_EVENT_LIMIT_REACHED');
    });

    it('não conta evento cancelado no limite do plano', function () {
        $organizer = Organizer::factory()->onPlan('FREE')->create();
        Event::factory()->count(3)->cancelled()->forOrganizer($organizer)->create();

        $this->actingAs($organizer->user)
            ->postJson('/api/v1/organizer/events', eventPayload())
            ->assertCreated();
    });

    it('bloqueia organizador suspenso', function () {
        $organizer = Organizer::factory()->blocked()->create();

        $this->actingAs($organizer->user)
            ->postJson('/api/v1/organizer/events', eventPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ORGANIZER_BLOCKED');
    });

    it('nega anônimo com 401', function () {
        $this->postJson('/api/v1/organizer/events', eventPayload())
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    });

    it('nega atleta sem perfil de organizador com 403', function () {
        $this->actingAs(User::factory()->player()->create())
            ->postJson('/api/v1/organizer/events', eventPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    });
});

describe('POST /api/v1/organizer/events/{slug}/publish', function () {
    it('publica e abre inscrições quando não há janela declarada', function () {
        $event = Event::factory()->forOrganizer($this->organizer)->create([
            'slug' => 'copa-teste',
            'registration_close_at' => null,
        ]);

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$event->slug}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', EventStatus::REGISTRATION_OPEN->value);

        expect($event->fresh()->published_at)->not->toBeNull();
    });

    it('publica sem abrir inscrições quando a janela ainda não começou', function () {
        $event = Event::factory()->forOrganizer($this->organizer)->create([
            'slug' => 'abre-depois',
            'registration_open_at' => now()->addWeek(),
            'registration_close_at' => now()->addWeeks(2),
        ]);

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$event->slug}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', EventStatus::PUBLISHED->value);
    });

    it('recusa publicar evento pago sem conta de recebimento', function () {
        $organizer = Organizer::factory()->withoutPaymentAccount()->create();
        $event = Event::factory()->forOrganizer($organizer)->create(['registration_fee_cents' => 9000]);

        $this->actingAs($organizer->user)
            ->postJson("/api/v1/organizer/events/{$event->slug}/publish")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EVENT_PAYMENT_ACCOUNT_REQUIRED');

        expect($event->fresh()->status)->toBe(EventStatus::DRAFT);
    });

    it('publica evento gratuito mesmo sem conta de recebimento', function () {
        $organizer = Organizer::factory()->withoutPaymentAccount()->create();
        $event = Event::factory()->free()->forOrganizer($organizer)->create();

        $this->actingAs($organizer->user)
            ->postJson("/api/v1/organizer/events/{$event->slug}/publish")
            ->assertOk();
    });

    it('recusa publicar duas vezes com 409', function () {
        $event = Event::factory()->registrationOpen()->forOrganizer($this->organizer)->create();

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$event->slug}/publish")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EVENT_INVALID_TRANSITION');
    });

    it('nega publicação de evento de terceiro (IDOR)', function () {
        $outro = Organizer::factory()->create();
        $event = Event::factory()->forOrganizer($outro)->create();

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$event->slug}/publish")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        expect($event->fresh()->status)->toBe(EventStatus::DRAFT);
    });

    it('permite ao super admin agir sobre evento de qualquer organizador', function () {
        $event = Event::factory()->forOrganizer($this->organizer)->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson("/api/v1/organizer/events/{$event->slug}/publish")
            ->assertOk();
    });
});

describe('encerramento e cancelamento', function () {
    it('encerra inscrições', function () {
        $event = Event::factory()->registrationOpen()->forOrganizer($this->organizer)->create();

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$event->slug}/close-registrations")
            ->assertOk()
            ->assertJsonPath('data.status', EventStatus::REGISTRATION_CLOSED->value);
    });

    it('cancela com justificativa e registra auditoria', function () {
        $event = Event::factory()->registrationOpen()->forOrganizer($this->organizer)->create();

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$event->slug}/cancel", [
                'justification' => 'Previsão de temporal para o dia inteiro.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', EventStatus::CANCELLED->value);

        $event = $event->fresh();

        expect($event->cancelled_at)->not->toBeNull()
            ->and($event->cancellation_reason)->toBe('Previsão de temporal para o dia inteiro.')
            ->and(AuditLog::query()->where('action', AuditAction::EVENT_CANCELLED)->exists())->toBeTrue();
    });

    it('recusa cancelamento sem justificativa', function () {
        $event = Event::factory()->registrationOpen()->forOrganizer($this->organizer)->create();

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$event->slug}/cancel", ['justification' => 'chuva'])
            ->assertStatus(422);

        expect($event->fresh()->status)->toBe(EventStatus::REGISTRATION_OPEN);
    });
});

describe('PATCH /api/v1/organizer/events/{slug}', function () {
    it('edita rascunho e recalcula o slug pelo novo nome', function () {
        $event = Event::factory()->forOrganizer($this->organizer)->create(['slug' => 'nome-antigo']);

        $this->actingAs($this->organizerUser)
            ->patchJson("/api/v1/organizer/events/{$event->slug}", eventPayload(['name' => 'Nome Novo']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Nome Novo')
            ->assertJsonPath('data.slug', 'nome-novo');
    });

    it('preserva o link de evento publicado mesmo se o nome mudar', function () {
        $event = Event::factory()->registrationOpen()->forOrganizer($this->organizer)->create([
            'slug' => 'link-ja-divulgado',
        ]);

        $this->actingAs($this->organizerUser)
            ->patchJson("/api/v1/organizer/events/{$event->slug}", eventPayload([
                'name' => 'Outro Nome',
                'justification' => 'Correção do nome oficial do campeonato.',
            ]))
            ->assertOk()
            ->assertJsonPath('data.slug', 'link-ja-divulgado');
    });

    it('exige justificativa para mudar data de evento publicado', function () {
        $event = Event::factory()->registrationOpen()->forOrganizer($this->organizer)->create();

        $this->actingAs($this->organizerUser)
            ->patchJson("/api/v1/organizer/events/{$event->slug}", eventPayload(['date' => '2026-12-20']))
            ->assertStatus(422);
    });

    it('não exige justificativa em rascunho', function () {
        $event = Event::factory()->forOrganizer($this->organizer)->create();

        $this->actingAs($this->organizerUser)
            ->patchJson("/api/v1/organizer/events/{$event->slug}", eventPayload(['date' => '2026-12-20']))
            ->assertOk();
    });

    it('recusa editar evento finalizado com 409', function () {
        $event = Event::factory()->forOrganizer($this->organizer)->create([
            'status' => EventStatus::FINISHED,
        ]);

        $this->actingAs($this->organizerUser)
            ->patchJson("/api/v1/organizer/events/{$event->slug}", eventPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EVENT_NOT_EDITABLE');
    });

    it('nega edição de evento de terceiro (IDOR)', function () {
        $event = Event::factory()->forOrganizer(Organizer::factory()->create())->create();

        $this->actingAs($this->organizerUser)
            ->patchJson("/api/v1/organizer/events/{$event->slug}", eventPayload())
            ->assertStatus(403);
    });
});

describe('GET /api/v1/organizer/events', function () {
    it('lista apenas os eventos do próprio organizador, inclusive rascunhos', function () {
        Event::factory()->count(2)->forOrganizer($this->organizer)->create();
        Event::factory()->count(3)->forOrganizer(Organizer::factory()->create())->create();

        $this->actingAs($this->organizerUser)
            ->getJson('/api/v1/organizer/events')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    });

    it('filtra por status', function () {
        Event::factory()->forOrganizer($this->organizer)->create();
        Event::factory()->registrationOpen()->forOrganizer($this->organizer)->create();

        $this->actingAs($this->organizerUser)
            ->getJson('/api/v1/organizer/events?status=DRAFT')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    });

    it('nega anônimo com 401', function () {
        $this->getJson('/api/v1/organizer/events')->assertUnauthorized();
    });

    it('nega atleta com 403', function () {
        $this->actingAs(User::factory()->withRole(Role::PLAYER)->create())
            ->getJson('/api/v1/organizer/events')
            ->assertStatus(403);
    });
});
