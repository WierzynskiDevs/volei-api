<?php

declare(strict_types=1);

use App\Modules\Brackets\Application\StartDrawAction;
use App\Modules\Brackets\Infrastructure\Models\BracketSlot;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Encaixe manual — PATCH .../bracket/slots/{position} (ADR 0011).
 * "eu posso selecionar a dupla e encaixar ela".
 */

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create();
    $this->event = Event::factory()->registrationClosed()->forOrganizer($this->organizer)->create();

    $this->teamA = confirmedTeam($this->event);
    $this->teamB = confirmedTeam($this->event);

    app(StartDrawAction::class)->execute($this->event, $this->organizerUser);
});

it('encaixa uma dupla numa posição vazia', function () {
    $this->actingAs($this->organizerUser)
        ->patchJson("/api/v1/organizer/events/{$this->event->slug}/bracket/slots/0", [
            'registration_group_id' => $this->teamA->id,
        ])
        ->assertOk();

    $slot = BracketSlot::query()->where('event_id', $this->event->id)->where('position', 0)->firstOrFail();

    expect($slot->registration_group_id)->toBe($this->teamA->id);
});

it('troca duas posições ao encaixar dupla que já ocupava outra (swap)', function () {
    $this->actingAs($this->organizerUser)
        ->patchJson("/api/v1/organizer/events/{$this->event->slug}/bracket/slots/0", [
            'registration_group_id' => $this->teamA->id,
        ])->assertOk();

    // teamA agora vai para a posição 1 — não pode ficar duplicada.
    $this->actingAs($this->organizerUser)
        ->patchJson("/api/v1/organizer/events/{$this->event->slug}/bracket/slots/1", [
            'registration_group_id' => $this->teamA->id,
        ])->assertOk();

    $slots = BracketSlot::query()->where('event_id', $this->event->id)->orderBy('position')->get();

    expect($slots->firstWhere('position', 1)->registration_group_id)->toBe($this->teamA->id)
        ->and($slots->firstWhere('position', 0)->registration_group_id)->toBeNull()
        ->and($slots->where('registration_group_id', $this->teamA->id))->toHaveCount(1);
});

it('limpa a posição quando registration_group_id é nulo', function () {
    $this->actingAs($this->organizerUser)
        ->patchJson("/api/v1/organizer/events/{$this->event->slug}/bracket/slots/0", [
            'registration_group_id' => $this->teamA->id,
        ])->assertOk();

    $this->actingAs($this->organizerUser)
        ->patchJson("/api/v1/organizer/events/{$this->event->slug}/bracket/slots/0", [
            'registration_group_id' => null,
        ])->assertOk();

    $slot = BracketSlot::query()->where('event_id', $this->event->id)->where('position', 0)->firstOrFail();

    expect($slot->registration_group_id)->toBeNull();
});

it('rejeita dupla de outro evento com 422', function () {
    $otherEventGroup = confirmedTeam(Event::factory()->registrationClosed()->forOrganizer($this->organizer)->create());

    $this->actingAs($this->organizerUser)
        ->patchJson("/api/v1/organizer/events/{$this->event->slug}/bracket/slots/0", [
            'registration_group_id' => $otherEventGroup->id,
        ])
        ->assertStatus(422);
});

it('rejeita encaixe depois de publicada a chave com 409', function () {
    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/publish")
        ->assertOk();

    $this->actingAs($this->organizerUser)
        ->patchJson("/api/v1/organizer/events/{$this->event->slug}/bracket/slots/0", [
            'registration_group_id' => $this->teamA->id,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DRAW_NOT_EDITABLE');
});

it('rejeita anônimo com 401', function () {
    $this->patchJson("/api/v1/organizer/events/{$this->event->slug}/bracket/slots/0", [
        'registration_group_id' => $this->teamA->id,
    ])->assertUnauthorized();
});

it('rejeita organizador de outro evento (IDOR) com 403', function () {
    $intruder = User::factory()->organizer()->create();
    Organizer::factory()->forUser($intruder)->create();

    $this->actingAs($intruder)
        ->patchJson("/api/v1/organizer/events/{$this->event->slug}/bracket/slots/0", [
            'registration_group_id' => $this->teamA->id,
        ])
        ->assertForbidden();
});
