<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Registrations\Domain\Enums\GroupStatus;
use App\Modules\Registrations\Domain\Enums\RegistrationStatus;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Cancelamento de inscrição — consumidores: /minhas-inscricoes e o fluxo
 * "solicitar cancelamento" do aditivo §18.
 */

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create();

    $this->event = Event::factory()
        ->forOrganizer($this->organizer)
        ->registrationOpen()
        ->create(['level_category' => LevelCategory::FREE, 'max_teams' => 8]);

    $this->athlete = User::factory()->player()->create();

    $this->registration = Registration::factory()
        ->forEvent($this->event)
        ->forUser($this->athlete)
        ->create();
});

describe('DELETE /api/v1/registrations/{id}', function (): void {

    it('o atleta cancela a própria inscrição', function (): void {
        $this->actingAs($this->athlete)
            ->deleteJson("/api/v1/registrations/{$this->registration->id}")
            ->assertOk()
            ->assertJsonPath('data.status', RegistrationStatus::CANCELLED->value);

        $fresh = $this->registration->fresh();

        expect($fresh->cancelled_at)->not->toBeNull()
            // A reserva é limpa: mantê-la faria a vaga parecer ocupada até vencer.
            ->and($fresh->reserved_until)->toBeNull();
    });

    it('libera a vaga no contador do evento', function (): void {
        expect($this->event->fresh()->teams_registered_count)->toBe(0);

        // Sincroniza o contador criando outra inscrição pela API.
        $other = User::factory()->player()->create();
        $this->actingAs($other)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", [
                'partner_mode' => 'INDIVIDUAL', 'accept_rules' => true,
            ])
            ->assertCreated();

        expect($this->event->fresh()->teams_registered_count)->toBe(2);

        $this->actingAs($this->athlete)
            ->deleteJson("/api/v1/registrations/{$this->registration->id}")
            ->assertOk();

        expect($this->event->fresh()->teams_registered_count)->toBe(1);
    });

    it('registra o motivo quando informado', function (): void {
        $this->actingAs($this->athlete)
            ->deleteJson("/api/v1/registrations/{$this->registration->id}", [
                'reason' => 'Lesão no tornozelo.',
            ])
            ->assertOk();

        expect($this->registration->fresh()->cancellation_reason)->toBe('Lesão no tornozelo.');
    });

    it('permite cancelar inscrição já confirmada — é o caminho do reembolso', function (): void {
        $confirmed = Registration::factory()
            ->forEvent($this->event)
            ->forUser(User::factory()->player()->create())
            ->confirmed()
            ->create();

        $this->actingAs($confirmed->user)
            ->deleteJson("/api/v1/registrations/{$confirmed->id}")
            ->assertOk()
            ->assertJsonPath('data.status', RegistrationStatus::CANCELLED->value);
    });

    it('recusa cancelar duas vezes, com 409', function (): void {
        $this->actingAs($this->athlete)
            ->deleteJson("/api/v1/registrations/{$this->registration->id}")
            ->assertOk();

        $this->actingAs($this->athlete)
            ->deleteJson("/api/v1/registrations/{$this->registration->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REGISTRATION_INVALID_TRANSITION');
    });

    it('audita REGISTRATION_CANCELLED', function (): void {
        $this->actingAs($this->athlete)
            ->deleteJson("/api/v1/registrations/{$this->registration->id}")
            ->assertOk();

        $log = AuditLog::query()
            ->where('action', AuditAction::REGISTRATION_CANCELLED->value)
            ->sole();

        expect($log->actor_id)->toBe($this->athlete->id)
            ->and($log->metadata['cancelled_by_self'])->toBeTrue();
    });
});

describe('DELETE /api/v1/registrations/{id} — dupla', function (): void {

    /*
     * ADR 0001 + aditivo §18: a dupla não é apagada. O parceiro que continua
     * inscrito não pode perder a participação porque o outro desistiu, e o
     * capitão precisa poder trocar de dupla depois.
     */
    it('marca a dupla como incompleta em vez de apagá-la', function (): void {
        $group = RegistrationGroup::factory()->forEvent($this->event)->create();

        $captain = Registration::factory()
            ->forEvent($this->event)
            ->forUser(User::factory()->player()->create())
            ->inGroup($group, captain: true)
            ->create();

        $partner = Registration::factory()
            ->forEvent($this->event)
            ->forUser(User::factory()->player()->create())
            ->inGroup($group)
            ->create();

        $this->actingAs($partner->user)
            ->deleteJson("/api/v1/registrations/{$partner->id}")
            ->assertOk();

        expect($group->fresh()->status)->toBe(GroupStatus::INCOMPLETE)
            ->and($captain->fresh()->status)->toBe(RegistrationStatus::PENDING_PAYMENT)
            ->and(RegistrationGroup::query()->whereKey($group->id)->exists())->toBeTrue();
    });

    it('cancela a dupla quando nenhum membro ativo sobra', function (): void {
        $group = RegistrationGroup::factory()->forEvent($this->event)->create();

        $only = Registration::factory()
            ->forEvent($this->event)
            ->forUser(User::factory()->player()->create())
            ->inGroup($group, captain: true)
            ->create();

        $this->actingAs($only->user)
            ->deleteJson("/api/v1/registrations/{$only->id}")
            ->assertOk();

        expect($group->fresh()->status)->toBe(GroupStatus::CANCELLED);
    });
});

describe('DELETE /api/v1/registrations/{id} — autorização', function (): void {

    it('nega anônimo com 401', function (): void {
        $this->deleteJson("/api/v1/registrations/{$this->registration->id}")
            ->assertUnauthorized();
    });

    /*
     * IDOR: outro atleta conhece o id e tenta cancelar a inscrição alheia.
     */
    it('nega outro atleta cancelar inscrição de terceiro (IDOR)', function (): void {
        $intruder = User::factory()->player()->create();

        $this->actingAs($intruder)
            ->deleteJson("/api/v1/registrations/{$this->registration->id}")
            ->assertForbidden();

        expect($this->registration->fresh()->status)->toBe(RegistrationStatus::PENDING_PAYMENT);
    });

    /** O organizador do evento pode cancelar — ex.: depois de reprovar o nível. */
    it('permite o organizador do evento cancelar', function (): void {
        $this->actingAs($this->organizerUser)
            ->deleteJson("/api/v1/registrations/{$this->registration->id}")
            ->assertOk();

        $log = AuditLog::query()
            ->where('action', AuditAction::REGISTRATION_CANCELLED->value)
            ->sole();

        expect($log->metadata['cancelled_by_self'])->toBeFalse();
    });

    it('nega organizador de OUTRO evento cancelar (IDOR)', function (): void {
        $intruderUser = User::factory()->organizer()->create();
        Organizer::factory()->forUser($intruderUser)->create();

        $this->actingAs($intruderUser)
            ->deleteJson("/api/v1/registrations/{$this->registration->id}")
            ->assertForbidden();
    });
});
