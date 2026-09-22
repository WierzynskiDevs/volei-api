<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Domain\Enums\RefereeInviteStatus;
use App\Modules\Operations\Domain\Services\RefereePhoneProtector;
use App\Modules\Operations\Infrastructure\Models\Court;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Juízes do evento — lado do organizador (ADR 0013 §5/§6/§8, S8b).
 *
 * Cobre CLAUDE.md §22: caminho feliz, validação, 401 anônimo, 403 de
 * terceiro (IDOR) e o conflito de telefone duplicado.
 */

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create();
    $this->event = Event::factory()->forOrganizer($this->organizer)->create();
});

describe('GET /api/v1/organizer/events/{slug}/referees', function (): void {
    it('lista os juízes do evento, com o telefone visível para o organizador', function (): void {
        EventReferee::factory()->forEvent($this->event)->create([
            'name' => 'Marcelo Faria',
            'phone_encrypted' => '+5541988884444',
        ]);

        $this->actingAs($this->organizerUser)
            ->getJson("/api/v1/organizer/events/{$this->event->slug}/referees")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Marcelo Faria')
            ->assertJsonPath('data.0.phone', '+5541988884444')
            ->assertJsonPath('data.0.invite_status', 'NOT_SENT');
    });

    it('nega anônimo com 401', function (): void {
        $this->getJson("/api/v1/organizer/events/{$this->event->slug}/referees")->assertUnauthorized();
    });

    it('nega organizador de outro evento — 403 (IDOR)', function (): void {
        $stranger = User::factory()->organizer()->create();

        $this->actingAs($stranger)
            ->getJson("/api/v1/organizer/events/{$this->event->slug}/referees")
            ->assertForbidden();
    });
});

describe('POST /api/v1/organizer/events/{slug}/referees', function (): void {
    it('cadastra o juiz como NOT_SENT e protege o telefone', function (): void {
        $response = $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/referees", [
                'name' => 'Marcelo Faria',
                'phone' => '(41) 98888-4444',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Marcelo Faria')
            ->assertJsonPath('data.invite_status', 'NOT_SENT');

        $referee = EventReferee::findOrFail($response->json('data.id'));
        $expectedHash = app(RefereePhoneProtector::class)->hash('(41) 98888-4444');

        expect($referee->phone_hash)->toBe($expectedHash)
            ->and($referee->phone_encrypted)->toBe('+5541988884444');
    });

    it('nunca devolve o hash de telefone na resposta', function (): void {
        $response = $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/referees", [
                'name' => 'Marcelo Faria',
                'phone' => '(41) 98888-4444',
            ]);

        expect(json_encode($response->json()))->not->toContain('phone_hash');
    });

    it('grava REFEREE_ADDED na auditoria sem o telefone em claro', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/referees", [
                'name' => 'Marcelo Faria',
                'phone' => '(41) 98888-4444',
            ])
            ->assertCreated();

        $log = AuditLog::where('action', AuditAction::REFEREE_ADDED)->firstOrFail();
        expect(json_encode($log->metadata))->not->toContain('988884444');
    });

    it('rejeita telefone com formato inválido — 422', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/referees", [
                'name' => 'Marcelo Faria',
                'phone' => 'abc',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.phone.0', 'Informe um telefone brasileiro válido, com DDD.');
    });

    it('recusa o mesmo telefone duas vezes no mesmo evento — 409', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/referees", [
                'name' => 'Marcelo Faria', 'phone' => '(41) 98888-4444',
            ])
            ->assertCreated();

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/referees", [
                'name' => 'Outro Nome', 'phone' => '41988884444', // mesmo telefone, formatação diferente
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REFEREE_PHONE_ALREADY_ADDED');
    });

    it('o mesmo telefone É permitido em eventos diferentes (dedup é por evento)', function (): void {
        $otherEvent = Event::factory()->forOrganizer($this->organizer)->create();

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/referees", [
                'name' => 'Marcelo Faria', 'phone' => '(41) 98888-4444',
            ])
            ->assertCreated();

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$otherEvent->slug}/referees", [
                'name' => 'Marcelo Faria', 'phone' => '(41) 98888-4444',
            ])
            ->assertCreated();
    });

    it('nega anônimo com 401', function (): void {
        $this->postJson("/api/v1/organizer/events/{$this->event->slug}/referees", [
            'name' => 'Marcelo Faria', 'phone' => '(41) 98888-4444',
        ])->assertUnauthorized();
    });
});

describe('POST /api/v1/organizer/events/{slug}/referees/{referee}/invite', function (): void {
    it('gera o token, devolve uma única vez e marca SENT', function (): void {
        $referee = EventReferee::factory()->forEvent($this->event)->create();

        $response = $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/referees/{$referee->id}/invite")
            ->assertOk()
            ->assertJsonPath('data.referee.invite_status', 'SENT');

        expect($response->json('data.invite_token'))->toBeString()->not->toBe('');

        $fresh = $referee->fresh();
        expect($fresh->invite_status)->toBe(RefereeInviteStatus::SENT)
            ->and($fresh->invited_at)->not->toBeNull();
    });

    it('reenviar convite invalida o token anterior', function (): void {
        $referee = EventReferee::factory()->forEvent($this->event)->create();

        $first = $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/referees/{$referee->id}/invite")
            ->json('data.invite_token');

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/referees/{$referee->id}/invite")
            ->assertOk();

        // O token antigo não aceita mais — reenviar o marca como usado
        // (InviteRefereeAction), então a resposta é "já usado", não "não
        // encontrado": o token existiu e foi invalidado, não é lixo.
        $this->postJson("/api/v1/referee-invitations/{$first}/accept")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REFEREE_INVITATION_INVALID');
    });

    it('nega organizador de outro evento — 403 (IDOR)', function (): void {
        $referee = EventReferee::factory()->forEvent($this->event)->create();
        $stranger = User::factory()->organizer()->create();

        $this->actingAs($stranger)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/referees/{$referee->id}/invite")
            ->assertForbidden();
    });

    it('devolve 404 para juiz de outro evento (IDOR de parâmetro)', function (): void {
        $otherEvent = Event::factory()->forOrganizer($this->organizer)->create();
        $foreignReferee = EventReferee::factory()->forEvent($otherEvent)->create();

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/events/{$this->event->slug}/referees/{$foreignReferee->id}/invite")
            ->assertNotFound();
    });
});

describe('PATCH /api/v1/organizer/events/{slug}/referees/{referee}', function (): void {
    it('atribui a quadra do juiz', function (): void {
        $court = Court::factory()->forEvent($this->event)->create(['label' => 'Quadra Central']);
        $referee = EventReferee::factory()->forEvent($this->event)->create();

        $this->actingAs($this->organizerUser)
            ->patchJson("/api/v1/organizer/events/{$this->event->slug}/referees/{$referee->id}", [
                'court_id' => $court->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.court_id', $court->id);
    });

    it('remove a quadra enviando null', function (): void {
        $court = Court::factory()->forEvent($this->event)->create();
        $referee = EventReferee::factory()->forEvent($this->event)->create(['court_id' => $court->id]);

        $this->actingAs($this->organizerUser)
            ->patchJson("/api/v1/organizer/events/{$this->event->slug}/referees/{$referee->id}", [
                'court_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.court_id', null);
    });

    it('recusa quadra de outro evento — 422 (IDOR de parâmetro)', function (): void {
        $otherEvent = Event::factory()->forOrganizer($this->organizer)->create();
        $foreignCourt = Court::factory()->forEvent($otherEvent)->create();
        $referee = EventReferee::factory()->forEvent($this->event)->create();

        $this->actingAs($this->organizerUser)
            ->patchJson("/api/v1/organizer/events/{$this->event->slug}/referees/{$referee->id}", [
                'court_id' => $foreignCourt->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COURT_NOT_IN_EVENT');
    });
});
