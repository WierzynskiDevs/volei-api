<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Infrastructure\Models\Court;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * "Minhas partidas" do juiz — GET /api/v1/referee/matches (ADR 0013 §5/§7/§8).
 *
 * Sessão por token (Sanctum polimórfico em `EventReferee`), nunca cookie —
 * nunca passa por `auth:sanctum` (esse guard é exclusivo do `User`/ADR 0005).
 */

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create();
    $this->event = Event::factory()->forOrganizer($this->organizer)->create();
    $this->court = Court::factory()->forEvent($this->event)->create();
    $this->referee = EventReferee::factory()->forEvent($this->event)->create();
});

function acceptRefereeInvite(EventReferee $referee, User $organizerUser): string
{
    $inviteToken = test()->actingAs($organizerUser)
        ->postJson("/api/v1/organizer/events/{$referee->event->slug}/referees/{$referee->id}/invite")
        ->json('data.invite_token');

    return test()->postJson("/api/v1/referee-invitations/{$inviteToken}/accept")
        ->json('data.session_token');
}

it('devolve um token de sessão ao aceitar o convite', function (): void {
    $sessionToken = acceptRefereeInvite($this->referee, $this->organizerUser);

    expect($sessionToken)->toBeString()->not->toBe('');
});

it('lista só as partidas atribuídas a este juiz', function (): void {
    $sessionToken = acceptRefereeInvite($this->referee, $this->organizerUser);

    $teamA = RegistrationGroup::factory()->forEvent($this->event)->complete()->create();
    $teamB = RegistrationGroup::factory()->forEvent($this->event)->complete()->create();
    $myMatch = GameMatch::factory()->forEvent($this->event)->betweenTeams($teamA, $teamB)
        ->ready($this->court, $this->referee)->create();

    $otherReferee = EventReferee::factory()->forEvent($this->event)->create();
    GameMatch::factory()->forEvent($this->event)->betweenTeams($teamA, $teamB)
        ->ready($this->court, $otherReferee)->create();

    $this->withHeader('Authorization', "Bearer {$sessionToken}")
        ->getJson('/api/v1/referee/matches')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $myMatch->id);
});

it('nega sem token — 401', function (): void {
    $this->getJson('/api/v1/referee/matches')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'REFEREE_SESSION_INVALID');
});

it('nega token inventado — 401', function (): void {
    $this->withHeader('Authorization', 'Bearer token-que-nao-existe')
        ->getJson('/api/v1/referee/matches')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'REFEREE_SESSION_INVALID');
});

it('nega token de sessão já expirado — 401', function (): void {
    $expired = $this->referee->createToken(
        'referee-session',
        ['referee-session'],
        CarbonImmutable::now()->subMinute(),
    )->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$expired}")
        ->getJson('/api/v1/referee/matches')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'REFEREE_SESSION_INVALID');
});

/*
 * Ciclo de vida operado pelo próprio juiz — POST .../start, PUT .../sets,
 * POST .../finish (ADR 0013 §5/§7, lacuna fechada em 25/09/2026: o backend
 * já previa este acesso desde a ADR 0013, mas as rotas nunca chegaram a
 * existir — só a leitura em `GET /referee/matches` estava ligada).
 */

function refereeReadyMatch(Event $event, Court $court, EventReferee $referee): GameMatch
{
    $teamA = RegistrationGroup::factory()->forEvent($event)->complete()->create();
    $teamB = RegistrationGroup::factory()->forEvent($event)->complete()->create();

    return GameMatch::factory()->forEvent($event)->betweenTeams($teamA, $teamB)
        ->ready($court, $referee)->create();
}

describe('POST /api/v1/referee/matches/{match}/start', function (): void {
    it('o juiz inicia a própria partida e o evento acompanha para IN_PROGRESS', function (): void {
        // Precisa de um evento em REGISTRATION_CLOSED (pré-requisito da
        // transição, ADR 0013 §3) — o `beforeEach` deixa o evento em DRAFT,
        // suficiente para os outros testes desta suíte, que não olham status.
        $event = Event::factory()->forOrganizer($this->organizer)->registrationClosed()->create();
        $court = Court::factory()->forEvent($event)->create();
        $referee = EventReferee::factory()->forEvent($event)->create();
        $sessionToken = acceptRefereeInvite($referee, $this->organizerUser);
        $match = refereeReadyMatch($event, $court, $referee);

        $this->withHeader('Authorization', "Bearer {$sessionToken}")
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/referee/matches/{$match->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'EM_ANDAMENTO');

        expect($event->fresh()->status)->toBe(EventStatus::IN_PROGRESS);
        expect(AuditLog::where('action', AuditAction::MATCH_STARTED)->count())->toBe(1);
    });

    it('exige Idempotency-Key — 400 sem o header', function (): void {
        $sessionToken = acceptRefereeInvite($this->referee, $this->organizerUser);
        $match = refereeReadyMatch($this->event, $this->court, $this->referee);

        $this->withHeader('Authorization', "Bearer {$sessionToken}")
            ->postJson("/api/v1/referee/matches/{$match->id}/start")
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    });

    it('nega iniciar partida de outro juiz — 403', function (): void {
        $sessionToken = acceptRefereeInvite($this->referee, $this->organizerUser);

        $otherReferee = EventReferee::factory()->forEvent($this->event)->create();
        $match = refereeReadyMatch($this->event, $this->court, $otherReferee);

        $this->withHeader('Authorization', "Bearer {$sessionToken}")
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/referee/matches/{$match->id}/start")
            ->assertForbidden();
    });
});

describe('PUT /api/v1/referee/matches/{match}/sets', function (): void {
    it('o juiz grava o set da própria partida', function (): void {
        $sessionToken = acceptRefereeInvite($this->referee, $this->organizerUser);
        $match = refereeReadyMatch($this->event, $this->court, $this->referee);

        $this->withHeader('Authorization', "Bearer {$sessionToken}")
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/referee/matches/{$match->id}/start")
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$sessionToken}")
            ->putJson("/api/v1/referee/matches/{$match->id}/sets", [
                'set_number' => 1, 'score_a' => 21, 'score_b' => 18,
            ])
            ->assertOk()
            ->assertJsonPath('data.sets.0.score_a', 21);

        expect(AuditLog::where('action', AuditAction::MATCH_SET_RECORDED)->count())->toBe(1);
    });

    it('nega gravar set de partida de outro juiz — 403', function (): void {
        $sessionToken = acceptRefereeInvite($this->referee, $this->organizerUser);

        $otherReferee = EventReferee::factory()->forEvent($this->event)->create();
        $match = GameMatch::factory()->forEvent($this->event)
            ->betweenTeams(
                RegistrationGroup::factory()->forEvent($this->event)->complete()->create(),
                RegistrationGroup::factory()->forEvent($this->event)->complete()->create(),
            )
            ->inProgress($this->court, $otherReferee)->create();

        $this->withHeader('Authorization', "Bearer {$sessionToken}")
            ->putJson("/api/v1/referee/matches/{$match->id}/sets", [
                'set_number' => 1, 'score_a' => 21, 'score_b' => 18,
            ])
            ->assertForbidden();
    });
});

describe('POST /api/v1/referee/matches/{match}/finish', function (): void {
    it('o juiz finaliza a própria partida quando os sets já decidem', function (): void {
        $sessionToken = acceptRefereeInvite($this->referee, $this->organizerUser);
        $match = refereeReadyMatch($this->event, $this->court, $this->referee);

        $this->withHeader('Authorization', "Bearer {$sessionToken}")
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/referee/matches/{$match->id}/start")
            ->assertOk();

        foreach ([[21, 15], [21, 18]] as $index => [$scoreA, $scoreB]) {
            $this->withHeader('Authorization', "Bearer {$sessionToken}")
                ->putJson("/api/v1/referee/matches/{$match->id}/sets", [
                    'set_number' => $index + 1, 'score_a' => $scoreA, 'score_b' => $scoreB,
                ])
                ->assertOk();
        }

        $this->withHeader('Authorization', "Bearer {$sessionToken}")
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/referee/matches/{$match->id}/finish")
            ->assertOk()
            ->assertJsonPath('data.status', 'FINALIZADA');

        expect(AuditLog::where('action', AuditAction::MATCH_FINISHED)->count())->toBe(1);
    });

    it('nega finalizar partida de outro juiz — 403', function (): void {
        $sessionToken = acceptRefereeInvite($this->referee, $this->organizerUser);

        $otherReferee = EventReferee::factory()->forEvent($this->event)->create();
        $match = GameMatch::factory()->forEvent($this->event)
            ->betweenTeams(
                RegistrationGroup::factory()->forEvent($this->event)->complete()->create(),
                RegistrationGroup::factory()->forEvent($this->event)->complete()->create(),
            )
            ->inProgress($this->court, $otherReferee)->create();

        $this->withHeader('Authorization', "Bearer {$sessionToken}")
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/referee/matches/{$match->id}/finish")
            ->assertForbidden();
    });
});
