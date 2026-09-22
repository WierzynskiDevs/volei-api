<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Domain\Enums\OrganizerStatus;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * `/admin/organizadores`: listagem com volume de eventos e governança
 * (botões "Advertir" e "Suspender").
 */

beforeEach(function (): void {
    $this->admin = User::factory()->superAdmin()->create();

    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()
        ->onPlan('PRO')
        ->create(['user_id' => $this->organizerUser->id, 'name' => 'Arena Norte Beach']);
});

describe('GET /api/v1/admin/organizers', function () {
    it('lista organizadores com plano, dono e situação', function () {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/organizers')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('name', 'Arena Norte Beach');

        expect($row['status'])->toBe(OrganizerStatus::REGULAR->value)
            ->and($row['plan']['code'])->toBe('PRO')
            ->and($row['owner']['id'])->toBe($this->organizerUser->id);
    });

    /*
     * A contagem vem do módulo Events, não de um JOIN cruzando fronteira
     * (ADR 0010 §2) — e vem agregada para a página inteira, não uma query por
     * linha (§26).
     */
    it('compõe a contagem de eventos vinda do módulo Events', function () {
        Event::factory()->count(3)->forOrganizer($this->organizer)->create();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/organizers')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('name', 'Arena Norte Beach');

        expect($row['events_count'])->toBe(3);
    });

    it('devolve zero em vez de nulo para organizador sem evento', function () {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/organizers')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('name', 'Arena Norte Beach');

        expect($row['events_count'])->toBe(0);
    });
});

describe('POST /api/v1/admin/organizers/{organizer}/status', function () {
    it('suspende o organizador e registra a trilha', function () {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/organizers/{$this->organizer->id}/status", [
                'status' => OrganizerStatus::BLOCKED->value,
                'reason' => 'Três eventos cancelados sem reembolso aos inscritos.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OrganizerStatus::BLOCKED->value);

        expect($this->organizer->fresh()->status)->toBe(OrganizerStatus::BLOCKED);

        $log = AuditLog::query()
            ->where('action', AuditAction::ORGANIZER_BLOCKED->value)
            ->where('target_id', $this->organizer->id)
            ->firstOrFail();

        expect($log->metadata['reason'])->toBe('Três eventos cancelados sem reembolso aos inscritos.');
    });

    it('adverte com ação de auditoria própria, distinta do bloqueio', function () {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/organizers/{$this->organizer->id}/status", [
                'status' => OrganizerStatus::ATTENTION->value,
                'reason' => 'Reclamações recorrentes sobre atraso no início das partidas.',
            ])
            ->assertOk();

        expect($this->organizer->fresh()->status)->toBe(OrganizerStatus::ATTENTION);

        expect(
            AuditLog::query()->where('action', AuditAction::ORGANIZER_STATUS_CHANGED->value)->exists()
        )->toBeTrue();

        // Advertência não é bloqueio: a trilha precisa distinguir os dois.
        expect(
            AuditLog::query()->where('action', AuditAction::ORGANIZER_BLOCKED->value)->exists()
        )->toBeFalse();
    });

    it('recusa situação fora do enum com 422', function () {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/organizers/{$this->organizer->id}/status", [
                'status' => 'BANIDO_PARA_SEMPRE',
                'reason' => 'valor inventado pelo cliente',
            ])
            ->assertStatus(422);

        expect($this->organizer->fresh()->status)->toBe(OrganizerStatus::REGULAR);
    });

    it('exige justificativa com 422', function () {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/organizers/{$this->organizer->id}/status", [
                'status' => OrganizerStatus::BLOCKED->value,
                'reason' => 'ruim',
            ])
            ->assertStatus(422);

        expect($this->organizer->fresh()->status)->toBe(OrganizerStatus::REGULAR);
    });

    /*
     * O bloqueio precisa valer para a regra que já existia: `CreateEventAction`
     * recusa organizador que não pode operar. Sem isto, "suspender" seria só um
     * rótulo na tela.
     */
    it('impede o organizador suspenso de criar evento', function () {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/organizers/{$this->organizer->id}/status", [
                'status' => OrganizerStatus::BLOCKED->value,
                'reason' => 'Suspensão preventiva enquanto a denúncia é apurada.',
            ])
            ->assertOk();

        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/events', [])
            ->assertStatus(422);
    });
});
