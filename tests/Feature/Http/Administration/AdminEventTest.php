<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * `/admin/eventos`: lista global e cancelamento administrativo.
 */

beforeEach(function (): void {
    $this->admin = User::factory()->superAdmin()->create();
    $this->organizer = Organizer::factory()->create();
});

describe('GET /api/v1/admin/events', function () {
    /*
     * A diferença essencial para a vitrine pública: aqui NÃO há recorte.
     * Rascunho e cancelado aparecem — é a definição do acesso global do §10.
     */
    it('mostra rascunho e cancelado, que a vitrine pública esconde', function () {
        Event::factory()->forOrganizer($this->organizer)->create(['name' => 'Rascunho Interno']);
        Event::factory()->forOrganizer($this->organizer)->cancelled()->create(['name' => 'Etapa Cancelada']);
        Event::factory()->forOrganizer($this->organizer)->published()->create(['name' => 'Etapa Publicada']);

        $names = collect(
            $this->actingAs($this->admin)->getJson('/api/v1/admin/events')->assertOk()->json('data')
        )->pluck('name')->all();

        expect($names)->toContain('Rascunho Interno', 'Etapa Cancelada', 'Etapa Publicada');
    });

    it('filtra por status', function () {
        Event::factory()->forOrganizer($this->organizer)->create(['name' => 'Rascunho Interno']);
        Event::factory()->forOrganizer($this->organizer)->published()->create(['name' => 'Etapa Publicada']);

        $names = collect(
            $this->actingAs($this->admin)
                ->getJson('/api/v1/admin/events?status='.EventStatus::DRAFT->value)
                ->assertOk()
                ->json('data')
        )->pluck('name')->all();

        expect($names)->toContain('Rascunho Interno')
            ->and($names)->not->toContain('Etapa Publicada');
    });

    it('identifica o organizador responsável pelo evento', function () {
        Event::factory()->forOrganizer($this->organizer)->published()->create();

        $row = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/events')
            ->assertOk()
            ->json('data.0');

        expect($row['organizer']['id'])->toBe($this->organizer->id);
    });
});

describe('POST /api/v1/admin/events/{slug}/cancel', function () {
    it('cancela evento de qualquer organizador e grava o motivo', function () {
        $event = Event::factory()->forOrganizer($this->organizer)->published()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/events/{$event->slug}/cancel", [
                'reason' => 'Evento cancelado por decisão administrativa após denúncia procedente.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', EventStatus::CANCELLED->value);

        $fresh = $event->fresh();

        expect($fresh->status)->toBe(EventStatus::CANCELLED)
            ->and($fresh->cancellation_reason)->not->toBeNull()
            ->and($fresh->cancelled_at)->not->toBeNull();
    });

    it('registra o cancelamento na trilha com o admin como ator', function () {
        $event = Event::factory()->forOrganizer($this->organizer)->published()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/events/{$event->slug}/cancel", [
                'reason' => 'Evento cancelado por decisão administrativa após denúncia procedente.',
            ])
            ->assertOk();

        $log = AuditLog::query()
            ->where('action', AuditAction::EVENT_CANCELLED->value)
            ->where('target_id', $event->id)
            ->firstOrFail();

        expect($log->actor_id)->toBe($this->admin->id);
    });

    /*
     * ADR 0010: o admin cancela pela MESMA action do organizador. Consequência
     * direta e desejada — a máquina de estados vale para ele também. Um evento
     * já cancelado não vira cancelado de novo porque quem pediu é admin.
     */
    it('respeita a máquina de estados e recusa transição inválida com 409', function () {
        $event = Event::factory()->forOrganizer($this->organizer)->cancelled()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/events/{$event->slug}/cancel", [
                'reason' => 'tentativa de cancelar duas vezes o mesmo evento',
            ])
            ->assertStatus(409);
    });

    it('exige justificativa com 422', function () {
        $event = Event::factory()->forOrganizer($this->organizer)->published()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/events/{$event->slug}/cancel", ['reason' => 'nao'])
            ->assertStatus(422);

        expect($event->fresh()->status)->not->toBe(EventStatus::CANCELLED);
    });
});
