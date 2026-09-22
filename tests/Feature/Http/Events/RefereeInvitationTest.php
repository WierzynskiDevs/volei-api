<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Domain\Enums\RefereeInviteStatus;
use App\Modules\Operations\Infrastructure\Models\Court;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Operations\Infrastructure\Models\RefereeInvitation;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Aceite do convite de juiz — rota PÚBLICA, sem sessão (ADR 0013 §5, S8b).
 */

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create();
    $this->event = Event::factory()->forOrganizer($this->organizer)->create(['name' => 'Copa Areia Curitiba']);
    $this->court = Court::factory()->forEvent($this->event)->create(['label' => 'Quadra Central']);
    $this->referee = EventReferee::factory()->forEvent($this->event)->create([
        'name' => 'Marcelo Faria',
        'court_id' => $this->court->id,
    ]);
});

function invite(EventReferee $referee, User $organizerUser): string
{
    return test()->actingAs($organizerUser)
        ->postJson("/api/v1/organizer/events/{$referee->event->slug}/referees/{$referee->id}/invite")
        ->json('data.invite_token');
}

describe('GET /api/v1/referee-invitations/{token}', function (): void {
    it('mostra o evento e a quadra do convite, sem exigir sessão', function (): void {
        $token = invite($this->referee, $this->organizerUser);

        $this->getJson("/api/v1/referee-invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('data.referee_name', 'Marcelo Faria')
            ->assertJsonPath('data.event_name', 'Copa Areia Curitiba')
            ->assertJsonPath('data.court_label', 'Quadra Central')
            ->assertJsonPath('data.already_accepted', false);
    });

    it('devolve 404 para token inexistente', function (): void {
        $this->getJson('/api/v1/referee-invitations/token-que-nao-existe')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'REFEREE_INVITATION_INVALID');
    });
});

describe('POST /api/v1/referee-invitations/{token}/accept', function (): void {
    it('aceita o convite e marca ACCEPTED', function (): void {
        $token = invite($this->referee, $this->organizerUser);

        $this->postJson("/api/v1/referee-invitations/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('data.referee_name', 'Marcelo Faria');

        $fresh = $this->referee->fresh();
        expect($fresh->invite_status)->toBe(RefereeInviteStatus::ACCEPTED)
            ->and($fresh->accepted_at)->not->toBeNull();
    });

    it('grava REFEREE_INVITATION_ACCEPTED com ator nulo (não há sessão)', function (): void {
        $token = invite($this->referee, $this->organizerUser);

        $this->postJson("/api/v1/referee-invitations/{$token}/accept")->assertOk();

        $log = AuditLog::where('action', AuditAction::REFEREE_INVITATION_ACCEPTED)->firstOrFail();
        expect($log->actor_id)->toBeNull();
    });

    it('recusa aceitar duas vezes — 409', function (): void {
        $token = invite($this->referee, $this->organizerUser);

        $this->postJson("/api/v1/referee-invitations/{$token}/accept")->assertOk();

        $this->postJson("/api/v1/referee-invitations/{$token}/accept")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REFEREE_INVITATION_INVALID');
    });

    it('devolve 404 para token inexistente', function (): void {
        $this->postJson('/api/v1/referee-invitations/token-que-nao-existe/accept')
            ->assertNotFound();
    });

    it('devolve 404 para token expirado', function (): void {
        $token = invite($this->referee, $this->organizerUser);

        RefereeInvitation::query()
            ->where('event_referee_id', $this->referee->id)
            ->update(['expires_at' => now()->subDay()]);

        $this->postJson("/api/v1/referee-invitations/{$token}/accept")
            ->assertNotFound();
    });
});
