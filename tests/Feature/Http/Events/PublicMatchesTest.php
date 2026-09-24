<?php

declare(strict_types=1);

use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Domain\Enums\MatchStatus;
use App\Modules\Operations\Infrastructure\Models\Court;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Operations\Infrastructure\Models\MatchSet;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Support\Str;

/*
 * Partidas públicas do evento — GET /api/v1/events/{slug}/matches (ADR
 * 0017, Q15). Consumidor: abas "Agenda" e "Resultados" de eventos.$slug.tsx
 * no volei-app.
 */

beforeEach(function (): void {
    $organizerUser = User::factory()->organizer()->create();
    $organizer = Organizer::factory()->forUser($organizerUser)->create();
    $this->event = Event::factory()
        ->forOrganizer($organizer)
        ->create(['status' => EventStatus::BRACKET_PUBLISHED, 'slug' => 'copa-publica']);

    $this->teamA = confirmedTeam($this->event);
    $this->teamB = confirmedTeam($this->event);

    $this->court = Court::factory()->forEvent($this->event)->create(['label' => 'Quadra Central']);
    $this->referee = EventReferee::factory()->forEvent($this->event)->create();
});

it('lista as partidas do evento sem exigir autenticação', function (): void {
    $match = GameMatch::factory()
        ->forEvent($this->event)
        ->betweenTeams($this->teamA, $this->teamB)
        ->finished($this->court, $this->referee)
        ->create(['phase' => 'Final']);

    MatchSet::factory()->forMatch($match)->create(['set_number' => 1, 'score_a' => 21, 'score_b' => 18]);
    MatchSet::factory()->forMatch($match)->create(['set_number' => 2, 'score_a' => 21, 'score_b' => 15]);

    $response = $this->getJson("/api/v1/events/{$this->event->slug}/matches")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->assertGuest();

    expect($response->json('data.0.phase'))->toBe('Final')
        ->and($response->json('data.0.status'))->toBe(MatchStatus::FINALIZADA->value)
        ->and($response->json('data.0.court_label'))->toBe('Quadra Central')
        ->and($response->json('data.0.sets'))->toHaveCount(2)
        ->and($response->json('data.0.sets.0.score_a'))->toBe(21);
});

it('mostra o primeiro nome de cada jogador nas duplas, nunca o nome completo', function (): void {
    $captain = $this->teamA->registrations()->where('is_captain', true)->firstOrFail()->user;
    $firstName = Str::of($captain->name)->trim()->before(' ')->toString();

    GameMatch::factory()
        ->forEvent($this->event)
        ->betweenTeams($this->teamA, $this->teamB)
        ->assigned($this->court)
        ->create();

    $response = $this->getJson("/api/v1/events/{$this->event->slug}/matches")->assertOk();

    expect($response->json('data.0.team_a_name'))->toContain($firstName)
        ->and($response->json('data.0.team_a_name'))->not->toContain($captain->name);
});

it('nunca expõe o juiz nem qualquer dado de contato', function (): void {
    GameMatch::factory()
        ->forEvent($this->event)
        ->betweenTeams($this->teamA, $this->teamB)
        ->ready($this->court, $this->referee)
        ->create();

    $response = $this->getJson("/api/v1/events/{$this->event->slug}/matches")->assertOk();
    $body = json_encode($response->json());

    expect($body)->not->toContain($this->referee->name)
        ->and($body)->not->toContain('@')
        ->and($response->json('data.0'))
        ->not->toHaveKey('referee_id')
        ->and($response->json('data.0'))->not->toHaveKey('referee_name');
});

it('devolve 404 antes do sorteio ser publicado', function (): void {
    $organizerUser = User::factory()->organizer()->create();
    $organizer = Organizer::factory()->forUser($organizerUser)->create();
    $event = Event::factory()
        ->forOrganizer($organizer)
        ->registrationClosed()
        ->create(['slug' => 'ainda-sem-chave']);

    $this->getJson("/api/v1/events/{$event->slug}/matches")->assertNotFound();
});

it('devolve 404 para evento inexistente', function (): void {
    $this->getJson('/api/v1/events/nao-existe/matches')->assertNotFound();
});

it('não faz N+1: uma query por relação, independente do número de partidas', function (): void {
    GameMatch::factory()->forEvent($this->event)->betweenTeams($this->teamA, $this->teamB)
        ->assigned($this->court)->create();
    GameMatch::factory()->forEvent($this->event)->betweenTeams($this->teamA, $this->teamB)
        ->assigned($this->court)->create();
    GameMatch::factory()->forEvent($this->event)->betweenTeams($this->teamA, $this->teamB)
        ->assigned($this->court)->create();

    DB::enableQueryLog();
    $this->getJson("/api/v1/events/{$this->event->slug}/matches")->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $courtQueries = collect($log)
        ->filter(fn (array $q): bool => str_contains((string) $q['query'], '"courts"'))
        ->count();

    expect($courtQueries)->toBeLessThanOrEqual(1);
});
