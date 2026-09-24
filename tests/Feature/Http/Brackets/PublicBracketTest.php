<?php

declare(strict_types=1);

use App\Modules\Brackets\Application\PublishBracketAction;
use App\Modules\Brackets\Application\RandomizeDrawAction;
use App\Modules\Brackets\Application\StartDrawAction;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Support\Str;

/*
 * Chave pública do evento — GET /api/v1/events/{slug}/bracket (ADR 0017,
 * Q15). Consumidor: aba "Chaveamento" de eventos.$slug.tsx no volei-app.
 */

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create();
    $this->event = Event::factory()
        ->registrationClosed()
        ->forOrganizer($this->organizer)
        ->create(['slug' => 'copa-publica']);

    $this->teamA = confirmedTeam($this->event);
    $this->teamB = confirmedTeam($this->event);

    app(StartDrawAction::class)->execute($this->event, $this->organizerUser);
    app(RandomizeDrawAction::class)->execute($this->event, $this->organizerUser);
    app(PublishBracketAction::class)->execute($this->event, $this->organizerUser);

    $this->event->refresh();
});

it('devolve a chave publicada sem exigir autenticação', function (): void {
    $this->getJson("/api/v1/events/{$this->event->slug}/bracket")
        ->assertOk()
        ->assertJsonPath('data.event_status', EventStatus::BRACKET_PUBLISHED->value)
        ->assertJsonPath('data.bracket_size', 2);

    $this->assertGuest();
});

it('mostra o primeiro nome de cada jogador, nunca o nome completo', function (): void {
    $captainRegistration = $this->teamA->registrations()->where('is_captain', true)->firstOrFail();
    $fullName = $captainRegistration->user->name;
    $firstName = Str::of($fullName)->trim()->before(' ')->toString();

    $response = $this->getJson("/api/v1/events/{$this->event->slug}/bracket")->assertOk();

    $slot = collect($response->json('data.slots'))
        ->firstWhere('registration_group.id', $this->teamA->id);

    expect($slot)->not->toBeNull()
        ->and($slot['registration_group']['display_name'])->toContain($firstName)
        ->and($slot['registration_group']['display_name'])->not->toContain($fullName);
});

it('nunca expõe e-mail, telefone ou a lista de membros da dupla', function (): void {
    $response = $this->getJson("/api/v1/events/{$this->event->slug}/bracket")->assertOk();

    $body = json_encode($response->json());

    expect($body)->not->toContain('@');

    foreach ($response->json('data.slots') as $slot) {
        if ($slot['registration_group'] !== null) {
            expect($slot['registration_group'])
                ->not->toHaveKey('members')
                ->and($slot['registration_group'])->not->toHaveKey('email')
                ->and($slot['registration_group'])->not->toHaveKey('phone')
                ->and($slot['registration_group'])->toHaveKeys(['id', 'display_name']);
        }
    }
});

it('devolve 404 antes da chave ser publicada — rascunho não é dado público', function (): void {
    $organizerUser = User::factory()->organizer()->create();
    $organizer = Organizer::factory()->forUser($organizerUser)->create();
    $draftEvent = Event::factory()
        ->registrationClosed()
        ->forOrganizer($organizer)
        ->create(['slug' => 'sorteio-em-andamento']);

    confirmedTeam($draftEvent);
    confirmedTeam($draftEvent);

    app(StartDrawAction::class)->execute($draftEvent, $organizerUser);

    expect($draftEvent->fresh()->status)->toBe(EventStatus::AWAITING_DRAW);

    $this->getJson("/api/v1/events/{$draftEvent->slug}/bracket")->assertNotFound();
});

it('devolve 404 para evento inexistente', function (): void {
    $this->getJson('/api/v1/events/nao-existe/bracket')->assertNotFound();
});

it('não faz N+1: elenco de posições em queries fixas', function (): void {
    DB::enableQueryLog();
    $this->getJson("/api/v1/events/{$this->event->slug}/bracket")->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $registrationGroupQueries = collect($log)
        ->filter(fn (array $q): bool => str_contains((string) $q['query'], '"registration_groups"'))
        ->count();

    expect($registrationGroupQueries)->toBeLessThanOrEqual(1);
});
