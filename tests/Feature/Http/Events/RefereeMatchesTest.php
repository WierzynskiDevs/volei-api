<?php

declare(strict_types=1);

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Infrastructure\Models\Court;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;

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
