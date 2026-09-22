<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Infrastructure\Models\Court;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Quadras do evento (ADR 0013 §2/§8, S8a).
 *
 * Cobre CLAUDE.md §22: caminho feliz, validação, 401 anônimo, 403 de
 * terceiro (IDOR — organizador de outro evento) e a substituição do conjunto
 * inteiro que `PUT` implementa.
 */

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create();
    $this->event = Event::factory()->forOrganizer($this->organizer)->create();
});

describe('GET /api/v1/organizer/events/{slug}/courts', function (): void {
    it('lista as quadras em ordem de posição', function (): void {
        Court::factory()->forEvent($this->event)->create(['label' => 'Quadra 2', 'position' => 1]);
        Court::factory()->forEvent($this->event)->create(['label' => 'Quadra Central', 'position' => 0]);

        $this->actingAs($this->organizerUser)
            ->getJson("/api/v1/organizer/events/{$this->event->slug}/courts")
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Quadra Central')
            ->assertJsonPath('data.1.label', 'Quadra 2');
    });

    it('nega anônimo com 401', function (): void {
        $this->getJson("/api/v1/organizer/events/{$this->event->slug}/courts")->assertUnauthorized();
    });

    it('nega organizador de outro evento — 403 (IDOR)', function (): void {
        $stranger = User::factory()->organizer()->create();

        $this->actingAs($stranger)
            ->getJson("/api/v1/organizer/events/{$this->event->slug}/courts")
            ->assertForbidden();
    });
});

describe('PUT /api/v1/organizer/events/{slug}/courts', function (): void {
    it('cria as quadras na ordem enviada', function (): void {
        $this->actingAs($this->organizerUser)
            ->putJson("/api/v1/organizer/events/{$this->event->slug}/courts", [
                'courts' => ['Quadra Central', 'Quadra 2', 'Quadra 3'],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Quadra Central')
            ->assertJsonPath('data.0.position', 0)
            ->assertJsonPath('data.2.label', 'Quadra 3')
            ->assertJsonPath('data.2.position', 2);

        expect(Court::where('event_id', $this->event->id)->count())->toBe(3);
    });

    it('substitui o conjunto inteiro — não acumula com a chamada anterior', function (): void {
        $this->actingAs($this->organizerUser)
            ->putJson("/api/v1/organizer/events/{$this->event->slug}/courts", ['courts' => ['Quadra 1', 'Quadra 2']])
            ->assertOk();

        $this->actingAs($this->organizerUser)
            ->putJson("/api/v1/organizer/events/{$this->event->slug}/courts", ['courts' => ['Quadra Única']])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        expect(Court::where('event_id', $this->event->id)->count())->toBe(1);
    });

    it('grava EVENT_COURTS_UPDATED na auditoria', function (): void {
        $this->actingAs($this->organizerUser)
            ->putJson("/api/v1/organizer/events/{$this->event->slug}/courts", ['courts' => ['Quadra 1']])
            ->assertOk();

        $log = AuditLog::where('action', AuditAction::EVENT_COURTS_UPDATED)->firstOrFail();
        expect($log->metadata['court_count'])->toBe(1);
    });

    it('rejeita nomes de quadra repetidos — 422', function (): void {
        $this->actingAs($this->organizerUser)
            ->putJson("/api/v1/organizer/events/{$this->event->slug}/courts", [
                'courts' => ['Quadra 1', 'Quadra 1'],
            ])
            ->assertStatus(422);
    });

    it('rejeita lista vazia — 422', function (): void {
        $this->actingAs($this->organizerUser)
            ->putJson("/api/v1/organizer/events/{$this->event->slug}/courts", ['courts' => []])
            ->assertStatus(422);
    });

    it('nega anônimo com 401', function (): void {
        $this->putJson("/api/v1/organizer/events/{$this->event->slug}/courts", ['courts' => ['Quadra 1']])
            ->assertUnauthorized();
    });

    it('nega organizador de outro evento — 403 (IDOR)', function (): void {
        $stranger = User::factory()->organizer()->create();

        $this->actingAs($stranger)
            ->putJson("/api/v1/organizer/events/{$this->event->slug}/courts", ['courts' => ['Quadra 1']])
            ->assertForbidden();

        expect(Court::where('event_id', $this->event->id)->count())->toBe(0);
    });
});
