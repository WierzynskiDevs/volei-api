<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Brackets\Application\StartDrawAction;
use App\Modules\Brackets\Infrastructure\Models\BracketSlot;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Publicação da chave — POST .../bracket/publish (ADR 0011).
 */

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create();
    $this->event = Event::factory()->registrationClosed()->forOrganizer($this->organizer)->create();

    $this->teamA = confirmedTeam($this->event);
    $this->teamB = confirmedTeam($this->event);
    $this->teamC = confirmedTeam($this->event);   // 3 duplas → chave de 4, 1 bye

    app(StartDrawAction::class)->execute($this->event, $this->organizerUser);
});

it('publica a chave, preenchendo posições vazias como bye', function () {
    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/randomize")
        ->assertOk();

    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/publish")
        ->assertOk()
        ->assertJsonPath('data.event_status', EventStatus::BRACKET_PUBLISHED->value);

    expect($this->event->fresh()->status)->toBe(EventStatus::BRACKET_PUBLISHED);

    $byes = BracketSlot::query()->where('event_id', $this->event->id)->where('is_bye', true)->count();
    expect($byes)->toBe(1);
});

it('registra na trilha de auditoria', function () {
    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/publish");

    $log = AuditLog::query()->where('action', AuditAction::EVENT_BRACKET_PUBLISHED)->firstOrFail();

    expect($log->target_id)->toBe($this->event->id);
});

it('rejeita publicar duas vezes com 409', function () {
    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/publish")
        ->assertOk();

    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/publish")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DRAW_NOT_EDITABLE');
});

it('rejeita publicar sem ter iniciado o sorteio', function () {
    $freshEvent = Event::factory()->registrationClosed()->forOrganizer($this->organizer)->create();
    confirmedTeam($freshEvent);
    confirmedTeam($freshEvent);

    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$freshEvent->slug}/bracket/publish")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DRAW_NOT_EDITABLE');
});

it('rejeita anônimo com 401', function () {
    $this->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/publish")
        ->assertUnauthorized();
});

it('rejeita organizador de outro evento (IDOR) com 403', function () {
    $intruder = User::factory()->organizer()->create();
    Organizer::factory()->forUser($intruder)->create();

    $this->actingAs($intruder)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/publish")
        ->assertForbidden();
});
