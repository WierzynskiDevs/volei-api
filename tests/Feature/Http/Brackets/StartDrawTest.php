<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Brackets\Infrastructure\Models\BracketSlot;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Início do sorteio — POST /api/v1/organizer/events/{slug}/bracket/start-draw
 * (ADR 0011).
 *
 * CLAUDE.md §22: caminho feliz, 401 anônimo, 403 papel errado, 403 IDOR, 409
 * conflito de estado. `confirmedTeam()` é compartilhada em tests/Pest.php.
 */

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create();
    $this->event = Event::factory()->registrationClosed()->forOrganizer($this->organizer)->create();
});

it('cria as posições da chave e move o evento para AGUARDANDO_SORTEIO', function () {
    confirmedTeam($this->event);
    confirmedTeam($this->event);
    confirmedTeam($this->event);

    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/start-draw")
        ->assertOk()
        ->assertJsonPath('data.event_status', EventStatus::AWAITING_DRAW->value)
        ->assertJsonPath('data.bracket_size', 4);

    expect($this->event->fresh()->status)->toBe(EventStatus::AWAITING_DRAW)
        ->and(BracketSlot::query()->where('event_id', $this->event->id)->count())->toBe(4);
});

it('registra na trilha de auditoria', function () {
    confirmedTeam($this->event);
    confirmedTeam($this->event);

    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/start-draw");

    $log = AuditLog::query()->where('action', AuditAction::EVENT_DRAW_STARTED)->firstOrFail();

    expect($log->actor_id)->toBe($this->organizerUser->id)
        ->and($log->target_id)->toBe($this->event->id);
});

it('rejeita anônimo com 401', function () {
    confirmedTeam($this->event);
    confirmedTeam($this->event);

    $this->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/start-draw")
        ->assertUnauthorized();
});

it('rejeita jogador com 403', function () {
    confirmedTeam($this->event);
    confirmedTeam($this->event);
    $player = User::factory()->player()->create();

    $this->actingAs($player)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/start-draw")
        ->assertForbidden();
});

it('rejeita organizador dono de outro evento (IDOR) com 403', function () {
    confirmedTeam($this->event);
    confirmedTeam($this->event);
    $intruder = User::factory()->organizer()->create();
    Organizer::factory()->forUser($intruder)->create();

    $this->actingAs($intruder)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/start-draw")
        ->assertForbidden();
});

it('rejeita menos de 2 duplas confirmadas com 409', function () {
    confirmedTeam($this->event);

    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/start-draw")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DRAW_NOT_POSSIBLE');
});

it('rejeita iniciar sorteio fora de INSCRICOES_ENCERRADAS com 409', function () {
    $openEvent = Event::factory()->registrationOpen()->forOrganizer($this->organizer)->create();
    confirmedTeam($openEvent);
    confirmedTeam($openEvent);

    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$openEvent->slug}/bracket/start-draw")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DRAW_NOT_POSSIBLE');
});

it('rejeita iniciar sorteio duas vezes', function () {
    confirmedTeam($this->event);
    confirmedTeam($this->event);

    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/start-draw")
        ->assertOk();

    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/start-draw")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DRAW_NOT_POSSIBLE');
});
