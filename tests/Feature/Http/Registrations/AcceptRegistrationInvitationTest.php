<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Registrations\Domain\Enums\RegistrationStatus;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Aceite de convite de dupla (Q14, ADR 0015).
 *
 * Cobre o exigido por CLAUDE.md §22: caminho feliz (pago e gratuito), 401
 * anônimo, 403 de terceiro (nem o capitão nem outro atleta aceitam por quem
 * foi convidado — é o teste de IDOR deste endpoint), 409 de conflito de estado.
 */

function setUpDuo(Event $event): array
{
    $captain = User::factory()->player()->create();
    $partner = User::factory()->player()->create();

    $group = RegistrationGroup::factory()->forEvent($event)->create();

    $captainRegistration = Registration::factory()
        ->forEvent($event)
        ->forUser($captain)
        ->inGroup($group, captain: true)
        ->create(['status' => RegistrationStatus::PENDING_PAYMENT]);

    $partnerRegistration = Registration::factory()
        ->forEvent($event)
        ->forUser($partner)
        ->inGroup($group)
        ->pendingAcceptance()
        ->create();

    return [$captain, $partner, $group, $captainRegistration, $partnerRegistration];
}

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create();
});

describe('POST /api/v1/registrations/{id}/accept — evento pago', function (): void {
    beforeEach(function (): void {
        $this->event = Event::factory()
            ->forOrganizer($this->organizer)
            ->registrationOpen()
            ->create(['max_teams' => 8]); // default é pago (registration_fee_cents = 13000)

        [$this->captain, $this->partner, $this->group, $this->captainRegistration, $this->partnerRegistration]
            = setUpDuo($this->event);
    });

    it('o parceiro aceita e vai para PENDING_PAYMENT, com reserva', function (): void {
        $this->actingAs($this->partner)
            ->postJson("/api/v1/registrations/{$this->partnerRegistration->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', RegistrationStatus::PENDING_PAYMENT->value);

        $fresh = $this->partnerRegistration->fresh();

        expect($fresh->status)->toBe(RegistrationStatus::PENDING_PAYMENT)
            ->and($fresh->reserved_until)->not->toBeNull()
            ->and($fresh->confirmed_at)->toBeNull();
    });

    it('não soma uma segunda vaga para a mesma dupla', function (): void {
        // As duas inscrições foram criadas direto por fábrica (sem passar por
        // EventOccupancy::syncCounter()), então o contador começa sem
        // refletir nem a vaga do capitão — o que importa é o valor final: UMA
        // vaga para a dupla, nunca duas, mesmo com as duas inscrições
        // ocupando depois do aceite.
        $this->actingAs($this->partner)
            ->postJson("/api/v1/registrations/{$this->partnerRegistration->id}/accept")
            ->assertOk();

        expect($this->event->fresh()->teams_registered_count)->toBe(1);
    });

    it('grava REGISTRATION_ACCEPTED na auditoria, com o ator correto', function (): void {
        $this->actingAs($this->partner)
            ->postJson("/api/v1/registrations/{$this->partnerRegistration->id}/accept")
            ->assertOk();

        $log = AuditLog::where('action', AuditAction::REGISTRATION_ACCEPTED)->firstOrFail();

        expect($log->actor_id)->toBe($this->partner->id)
            ->and($log->target_id)->toBe($this->partnerRegistration->id);
    });

    it('rejeita anônimo com 401', function (): void {
        $this->postJson("/api/v1/registrations/{$this->partnerRegistration->id}/accept")
            ->assertUnauthorized();
    });

    it('rejeita o capitão tentando aceitar pelo parceiro — 403 (IDOR)', function (): void {
        $this->actingAs($this->captain)
            ->postJson("/api/v1/registrations/{$this->partnerRegistration->id}/accept")
            ->assertForbidden();
    });

    it('rejeita um atleta qualquer tentando aceitar pelo parceiro — 403 (IDOR)', function (): void {
        $stranger = User::factory()->player()->create();

        $this->actingAs($stranger)
            ->postJson("/api/v1/registrations/{$this->partnerRegistration->id}/accept")
            ->assertForbidden();
    });

    it('rejeita o organizador tentando aceitar pelo parceiro — 403', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/registrations/{$this->partnerRegistration->id}/accept")
            ->assertForbidden();
    });

    it('recusa aceitar duas vezes — 409', function (): void {
        $this->actingAs($this->partner)
            ->postJson("/api/v1/registrations/{$this->partnerRegistration->id}/accept")
            ->assertOk();

        $this->actingAs($this->partner)
            ->postJson("/api/v1/registrations/{$this->partnerRegistration->id}/accept")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REGISTRATION_INVALID_TRANSITION');
    });

    it('recusa aceitar convite já cancelado — 409', function (): void {
        // status/cancelled_at ficam fora de $fillable de propósito (só a
        // action escreve) — atribuição direta contorna o guard só no teste.
        $this->partnerRegistration->status = RegistrationStatus::CANCELLED;
        $this->partnerRegistration->cancelled_at = now();
        $this->partnerRegistration->save();

        $this->actingAs($this->partner)
            ->postJson("/api/v1/registrations/{$this->partnerRegistration->id}/accept")
            ->assertStatus(409);
    });
});

describe('POST /api/v1/registrations/{id}/accept — evento gratuito', function (): void {
    it('o parceiro aceita e a inscrição já confirma direto', function (): void {
        $event = Event::factory()->forOrganizer($this->organizer)->registrationOpen()->free()->create();

        [, $partner, , , $partnerRegistration] = setUpDuo($event);

        $this->actingAs($partner)
            ->postJson("/api/v1/registrations/{$partnerRegistration->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', RegistrationStatus::CONFIRMED->value);

        $fresh = $partnerRegistration->fresh();

        expect($fresh->confirmed_at)->not->toBeNull()
            ->and($fresh->reserved_until)->toBeNull();
    });
});
