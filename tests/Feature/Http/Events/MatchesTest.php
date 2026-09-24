<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use App\Modules\Operations\Infrastructure\Models\Court;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Support\Str;

/*
 * Ciclo de vida da partida — organizador (ADR 0013 §4/§7/§8, S9).
 *
 * Cobre CLAUDE.md §22: caminho feliz, 401 anônimo, 403 de terceiro (IDOR),
 * 409 de conflito de estado, idempotência de início/fim.
 */

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create();
    $this->event = Event::factory()->forOrganizer($this->organizer)->registrationClosed()->create();
    $this->teamA = RegistrationGroup::factory()->forEvent($this->event)->complete()->create();
    $this->teamB = RegistrationGroup::factory()->forEvent($this->event)->complete()->create();
});

describe('POST /api/v1/organizer/events/{slug}/matches', function (): void {
    it('monta o confronto entre duas duplas elegíveis', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches", [
                'team_a_id' => $this->teamA->id,
                'team_b_id' => $this->teamB->id,
                'phase' => 'Semifinal',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDENTE')
            ->assertJsonPath('data.phase', 'Semifinal')
            ->assertJsonPath('data.team_a_id', $this->teamA->id)
            ->assertJsonPath('data.team_b_id', $this->teamB->id);

        $log = AuditLog::where('action', AuditAction::MATCH_CREATED)->firstOrFail();
        expect($log->metadata['team_a_id'])->toBe($this->teamA->id);
    });

    it('rejeita a mesma dupla nos dois lados — 422', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches", [
                'team_a_id' => $this->teamA->id,
                'team_b_id' => $this->teamA->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MATCH_TEAM_NOT_ELIGIBLE');
    });

    it('rejeita dupla de outro evento — 422', function (): void {
        $foreignTeam = RegistrationGroup::factory()->complete()->create();

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches", [
                'team_a_id' => $this->teamA->id,
                'team_b_id' => $foreignTeam->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MATCH_TEAM_NOT_ELIGIBLE');
    });

    it('nega anônimo com 401', function (): void {
        $this->postJson("/api/v1/organizer/events/{$this->event->slug}/matches", [
            'team_a_id' => $this->teamA->id,
            'team_b_id' => $this->teamB->id,
        ])->assertUnauthorized();
    });

    it('nega organizador de outro evento — 403 (IDOR)', function (): void {
        $stranger = User::factory()->organizer()->create();

        $this->actingAs($stranger)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches", [
                'team_a_id' => $this->teamA->id,
                'team_b_id' => $this->teamB->id,
            ])
            ->assertForbidden();
    });
});

describe('atribuição de quadra e juiz', function (): void {
    it('atribui quadra e juiz, chegando a PRONTA', function (): void {
        $court = Court::factory()->forEvent($this->event)->create();
        $referee = EventReferee::factory()->forEvent($this->event)->create();
        $match = createMatch($this->event, $this->teamA, $this->teamB);

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/court", [
                'court_id' => $court->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ATRIBUIDA');

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/referee", [
                'referee_id' => $referee->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'PRONTA');
    });

    /*
     * B4 (docs/PLANO-CONTINUACAO-2026-09.md): "atribuição de partida" notifica
     * só na transição PARA PRONTA — nunca em cada atribuição parcial isolada,
     * senão duplicaria e-mail numa reatribuição de quadra já pronta.
     */
    it('notifica os jogadores quando a partida fica PRONTA, só uma vez', function (): void {
        $teamA = confirmedTeam($this->event);
        $teamB = confirmedTeam($this->event);
        $court = Court::factory()->forEvent($this->event)->create();
        $secondCourt = Court::factory()->forEvent($this->event)->create();
        $referee = EventReferee::factory()->forEvent($this->event)->create();
        $match = createMatch($this->event, $teamA, $teamB);

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/court", [
                'court_id' => $court->id,
            ])->assertOk();

        expect(NotificationDelivery::query()->count())->toBe(0);

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/referee", [
                'referee_id' => $referee->id,
            ])->assertOk()->assertJsonPath('data.status', 'PRONTA');

        expect(NotificationDelivery::query()->where('type', NotificationType::MATCH_READY)->count())->toBe(4);

        // Reatribuir a quadra de uma partida já PRONTA não reabre o aviso.
        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/court", [
                'court_id' => $secondCourt->id,
            ])->assertOk();

        expect(NotificationDelivery::query()->where('type', NotificationType::MATCH_READY)->count())->toBe(4);
    });

    it('recusa quadra de outro evento — 422 (IDOR de parâmetro)', function (): void {
        $otherEvent = Event::factory()->forOrganizer($this->organizer)->create();
        $foreignCourt = Court::factory()->forEvent($otherEvent)->create();
        $match = createMatch($this->event, $this->teamA, $this->teamB);

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/court", [
                'court_id' => $foreignCourt->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COURT_NOT_IN_EVENT');
    });

    it('devolve 404 para partida de outro evento (IDOR de parâmetro)', function (): void {
        $otherEvent = Event::factory()->forOrganizer($this->organizer)->create();
        $otherTeamA = RegistrationGroup::factory()->forEvent($otherEvent)->complete()->create();
        $otherTeamB = RegistrationGroup::factory()->forEvent($otherEvent)->complete()->create();
        $foreignMatch = createMatch($otherEvent, $otherTeamA, $otherTeamB);

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$foreignMatch->id}/court", [
                'court_id' => null,
            ])
            ->assertNotFound();
    });
});

describe('POST /api/v1/organizer/events/{slug}/matches/{match}/start', function (): void {
    it('exige Idempotency-Key — 400 sem o header', function (): void {
        $match = readyMatch($this->event, $this->teamA, $this->teamB);

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/start")
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    });

    it('inicia a partir de PRONTA e move o evento para IN_PROGRESS', function (): void {
        $match = readyMatch($this->event, $this->teamA, $this->teamB);

        $this->actingAs($this->organizerUser)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'EM_ANDAMENTO');

        expect($this->event->fresh()->status)->toBe(EventStatus::IN_PROGRESS);

        $log = AuditLog::where('action', AuditAction::MATCH_STARTED)->first();
        expect($log)->not->toBeNull();

        $eventLog = AuditLog::where('action', AuditAction::EVENT_STARTED)->first();
        expect($eventLog)->not->toBeNull();
    });

    it('não pula de PENDENTE direto para EM_ANDAMENTO — 409', function (): void {
        $match = createMatch($this->event, $this->teamA, $this->teamB);

        $this->actingAs($this->organizerUser)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/start")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MATCH_INVALID_TRANSITION');
    });

    it('a mesma Idempotency-Key repetida devolve a mesma partida, sem duplicar efeito', function (): void {
        $match = readyMatch($this->event, $this->teamA, $this->teamB);
        $key = (string) Str::uuid7();

        $first = $this->actingAs($this->organizerUser)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/start")
            ->assertOk();

        $second = $this->actingAs($this->organizerUser)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/start")
            ->assertOk();

        expect($first->json('data.started_at'))->toBe($second->json('data.started_at'));
        expect(AuditLog::where('action', AuditAction::MATCH_STARTED)->count())->toBe(1);
    });

    it('Idempotency-Key diferente contra partida já iniciada é conflito — 409', function (): void {
        $match = readyMatch($this->event, $this->teamA, $this->teamB);

        $this->actingAs($this->organizerUser)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/start")
            ->assertOk();

        $this->actingAs($this->organizerUser)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/start")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MATCH_INVALID_TRANSITION');
    });

    it('nega anônimo com 401', function (): void {
        $match = readyMatch($this->event, $this->teamA, $this->teamB);

        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/start")
            ->assertUnauthorized();
    });
});

describe('PUT /api/v1/organizer/events/{slug}/matches/{match}/sets', function (): void {
    it('grava o set e corrige um já gravado gerando MATCH_SET_CORRECTED', function (): void {
        $match = startedMatch($this->event, $this->teamA, $this->teamB);

        $this->actingAs($this->organizerUser)
            ->putJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/sets", [
                'set_number' => 1, 'score_a' => 21, 'score_b' => 18,
            ])
            ->assertOk()
            ->assertJsonPath('data.sets.0.score_a', 21);

        expect(AuditLog::where('action', AuditAction::MATCH_SET_RECORDED)->count())->toBe(1);

        $this->actingAs($this->organizerUser)
            ->putJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/sets", [
                'set_number' => 1, 'score_a' => 21, 'score_b' => 19,
            ])
            ->assertOk()
            ->assertJsonPath('data.sets.0.score_b', 19);

        expect(AuditLog::where('action', AuditAction::MATCH_SET_CORRECTED)->count())->toBe(1);
    });

    it('recusa gravar set antes da partida começar — 409', function (): void {
        $match = createMatch($this->event, $this->teamA, $this->teamB);

        $this->actingAs($this->organizerUser)
            ->putJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/sets", [
                'set_number' => 1, 'score_a' => 21, 'score_b' => 18,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MATCH_SETS_NOT_RECORDABLE');
    });
});

describe('POST /api/v1/organizer/events/{slug}/matches/{match}/finish', function (): void {
    it('finaliza quando os sets já decidem — best of 3', function (): void {
        $match = startedMatch($this->event, $this->teamA, $this->teamB);
        recordSet($this, $this->event, $match, 1, 21, 15);
        recordSet($this, $this->event, $match, 2, 21, 18);

        $this->actingAs($this->organizerUser)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/finish")
            ->assertOk()
            ->assertJsonPath('data.status', 'FINALIZADA');

        expect(AuditLog::where('action', AuditAction::MATCH_FINISHED)->count())->toBe(1);
    });

    it('recusa finalizar sem placar decidido — 422', function (): void {
        $match = startedMatch($this->event, $this->teamA, $this->teamB);
        recordSet($this, $this->event, $match, 1, 21, 15);

        $this->actingAs($this->organizerUser)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/finish")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MATCH_RESULT_NOT_DECIDED');
    });

    it('a mesma Idempotency-Key repetida não duplica auditoria', function (): void {
        $match = startedMatch($this->event, $this->teamA, $this->teamB);
        recordSet($this, $this->event, $match, 1, 21, 15);
        recordSet($this, $this->event, $match, 2, 21, 18);
        $key = (string) Str::uuid7();

        $this->actingAs($this->organizerUser)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/finish")
            ->assertOk();

        $this->actingAs($this->organizerUser)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/finish")
            ->assertOk();

        expect(AuditLog::where('action', AuditAction::MATCH_FINISHED)->count())->toBe(1);
    });
});

describe('POST /api/v1/organizer/events/{slug}/matches/{match}/cancel', function (): void {
    it('cancela uma partida que ainda não começou', function (): void {
        $match = createMatch($this->event, $this->teamA, $this->teamB);

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELADA');
    });

    it('não cancela partida em andamento — 409', function (): void {
        $match = startedMatch($this->event, $this->teamA, $this->teamB);

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/matches/{$match->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MATCH_INVALID_TRANSITION');
    });
});

function createMatch(Event $event, RegistrationGroup $teamA, RegistrationGroup $teamB): GameMatch
{
    return GameMatch::factory()
        ->forEvent($event)
        ->betweenTeams($teamA, $teamB)
        ->create();
}

function readyMatch(Event $event, RegistrationGroup $teamA, RegistrationGroup $teamB): GameMatch
{
    $court = Court::factory()->forEvent($event)->create();
    $referee = EventReferee::factory()->forEvent($event)->create();

    return GameMatch::factory()
        ->forEvent($event)
        ->betweenTeams($teamA, $teamB)
        ->ready($court, $referee)
        ->create();
}

function startedMatch(Event $event, RegistrationGroup $teamA, RegistrationGroup $teamB): GameMatch
{
    $court = Court::factory()->forEvent($event)->create();
    $referee = EventReferee::factory()->forEvent($event)->create();

    return GameMatch::factory()
        ->forEvent($event)
        ->betweenTeams($teamA, $teamB)
        ->inProgress($court, $referee)
        ->create();
}

function recordSet(mixed $test, Event $event, GameMatch $match, int $setNumber, int $scoreA, int $scoreB): void
{
    $test->actingAs($test->organizerUser)
        ->putJson("/api/v1/organizer/events/{$event->slug}/matches/{$match->id}/sets", [
            'set_number' => $setNumber, 'score_a' => $scoreA, 'score_b' => $scoreB,
        ])
        ->assertOk();
}
