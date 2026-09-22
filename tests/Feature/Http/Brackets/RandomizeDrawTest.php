<?php

declare(strict_types=1);

use App\Modules\Brackets\Application\StartDrawAction;
use App\Modules\Brackets\Infrastructure\Models\BracketSlot;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Sorteio aleatório — POST .../bracket/randomize (ADR 0011).
 */

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create();
    $this->event = Event::factory()->registrationClosed()->forOrganizer($this->organizer)->create();

    $this->teamA = confirmedTeam($this->event);
    $this->teamB = confirmedTeam($this->event);
    $this->teamC = confirmedTeam($this->event);
});

it('preenche todas as posições sem repetir dupla', function () {
    app(StartDrawAction::class)->execute($this->event, $this->organizerUser);

    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/randomize")
        ->assertOk();

    $slots = BracketSlot::query()->where('event_id', $this->event->id)->get();
    $groupIds = $slots->pluck('registration_group_id')->filter();

    expect($slots)->toHaveCount(4)
        ->and($groupIds)->toHaveCount(3)
        ->and($groupIds->unique())->toHaveCount(3)
        ->and($groupIds->all())->toEqualCanonicalizing([
            $this->teamA->id, $this->teamB->id, $this->teamC->id,
        ]);
});

it('pode ser chamado de novo antes de publicar (reembaralha do zero)', function () {
    app(StartDrawAction::class)->execute($this->event, $this->organizerUser);

    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/randomize")
        ->assertOk();

    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/randomize")
        ->assertOk();

    $groupIds = BracketSlot::query()->where('event_id', $this->event->id)->get()->pluck('registration_group_id')->filter();

    expect($groupIds->unique())->toHaveCount(3);
});

it('rejeita sortear antes de iniciar o sorteio', function () {
    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/randomize")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DRAW_NOT_EDITABLE');
});

it('rejeita anônimo com 401', function () {
    app(StartDrawAction::class)->execute($this->event, $this->organizerUser);

    $this->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/randomize")
        ->assertUnauthorized();
});

it('rejeita organizador de outro evento (IDOR) com 403', function () {
    app(StartDrawAction::class)->execute($this->event, $this->organizerUser);
    $intruder = User::factory()->organizer()->create();
    Organizer::factory()->forUser($intruder)->create();

    $this->actingAs($intruder)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/randomize")
        ->assertForbidden();
});
