<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Registrations\Domain\Enums\LevelReview;
use App\Modules\Registrations\Domain\Enums\RegistrationStatus;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Users\Domain\Enums\PlayerLevel;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Inscrições pelo lado do organizador — fila de análise de nível (aditivo §17
 * e §18) e lista de inscritos.
 *
 * Cobre o exigido por CLAUDE.md §22 e pelo aditivo §26: caminho feliz, 401
 * anônimo, 403 papel errado, 403 recurso de terceiro (IDOR) e 409 conflito.
 */

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create();

    $this->event = Event::factory()
        ->forOrganizer($this->organizer)
        ->registrationOpen()
        ->create(['level_category' => LevelCategory::INTERMEDIATE]);

    $this->athlete = User::factory()->player()->create(['level' => PlayerLevel::OPEN]);

    $this->registration = Registration::factory()
        ->forEvent($this->event)
        ->forUser($this->athlete)
        ->awaitingLevelReview()
        ->create();
});

describe('GET /api/v1/organizer/events/{slug}/registrations', function (): void {

    it('lista as inscrições do evento do próprio organizador', function (): void {
        $this->actingAs($this->organizerUser)
            ->getJson("/api/v1/organizer/events/{$this->event->slug}/registrations")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->registration->id)
            ->assertJsonPath('data.0.player.name', $this->athlete->name);
    });

    it('filtra a fila de análise pendente', function (): void {
        Registration::factory()->count(3)->forEvent($this->event)->create();

        $this->actingAs($this->organizerUser)
            ->getJson("/api/v1/organizer/events/{$this->event->slug}/registrations?level_review=REQUIRED")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.level_review', LevelReview::REQUIRED->value);
    });

    it('filtra por status da inscrição', function (): void {
        Registration::factory()->count(2)->forEvent($this->event)->confirmed()->create();

        $this->actingAs($this->organizerUser)
            ->getJson("/api/v1/organizer/events/{$this->event->slug}/registrations?status=CONFIRMED")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    /*
     * LGPD (§12): o organizador precisa do NOME para operar o campeonato, mas
     * não de e-mail nem telefone. O backend não envia o dado que o solicitante
     * não tem direito de ver — não se confia no cliente para mascarar.
     */
    it('não vaza e-mail nem telefone do atleta', function (): void {
        $this->athlete->update(['email' => 'atleta@exemplo.com']);

        $response = $this->actingAs($this->organizerUser)
            ->getJson("/api/v1/organizer/events/{$this->event->slug}/registrations")
            ->assertOk();

        $body = $response->getContent();

        expect($body)->not->toContain('atleta@exemplo.com')
            ->and($response->json('data.0.player'))->toHaveKeys(['id', 'name', 'level'])
            ->and($response->json('data.0.player'))->not->toHaveKey('email')
            ->and($response->json('data.0.player'))->not->toHaveKey('phone');
    });

    it('nega anônimo com 401', function (): void {
        $this->getJson("/api/v1/organizer/events/{$this->event->slug}/registrations")
            ->assertUnauthorized();
    });

    it('nega atleta com 403', function (): void {
        $this->actingAs($this->athlete)
            ->getJson("/api/v1/organizer/events/{$this->event->slug}/registrations")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    });

    /*
     * IDOR: conhecer o slug do evento de outro organizador não dá acesso à
     * lista de inscritos dele.
     */
    it('nega listar inscrições de evento de terceiro (IDOR)', function (): void {
        $otherUser = User::factory()->organizer()->create();
        $other = Organizer::factory()->forUser($otherUser)->create();
        $otherEvent = Event::factory()->forOrganizer($other)->registrationOpen()->create();

        Registration::factory()->forEvent($otherEvent)->create();

        $this->actingAs($this->organizerUser)
            ->getJson("/api/v1/organizer/events/{$otherEvent->slug}/registrations")
            ->assertForbidden();
    });
});

describe('GET /api/v1/organizer/registrations (fila multi-evento)', function (): void {

    it('lista a fila de análise de todos os eventos do organizador', function (): void {
        $secondEvent = Event::factory()
            ->forOrganizer($this->organizer)
            ->registrationOpen()
            ->create(['level_category' => LevelCategory::BEGINNER]);

        Registration::factory()->forEvent($secondEvent)->awaitingLevelReview()->create();

        $this->actingAs($this->organizerUser)
            ->getJson('/api/v1/organizer/registrations?level_review=REQUIRED')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    /*
     * O escopo vem da sessão. Sem o filtro por `organizer_id`, esta rota seria
     * um vazamento de fila alheia sem nem precisar de id na URL.
     */
    it('não mostra inscrições de evento de outro organizador', function (): void {
        $otherUser = User::factory()->organizer()->create();
        $other = Organizer::factory()->forUser($otherUser)->create();
        $otherEvent = Event::factory()->forOrganizer($other)->registrationOpen()->create([
            'level_category' => LevelCategory::INTERMEDIATE,
        ]);

        Registration::factory()->forEvent($otherEvent)->awaitingLevelReview()->create();

        $this->actingAs($this->organizerUser)
            ->getJson('/api/v1/organizer/registrations?level_review=REQUIRED')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->registration->id);
    });

    /*
     * Conta sem perfil de organizador abre o mesmo painel. Lista vazia é a
     * resposta honesta — 403 viraria erro visível numa área acessível.
     */
    it('devolve lista vazia para conta sem perfil de organizador', function (): void {
        $this->actingAs($this->athlete)
            ->getJson('/api/v1/organizer/registrations?level_review=REQUIRED')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('nega anônimo com 401', function (): void {
        $this->getJson('/api/v1/organizer/registrations')->assertUnauthorized();
    });
});

describe('POST /api/v1/organizer/registrations/{id}/approve', function (): void {

    it('aprova a análise registrando decisão, autor e data (aditivo §17)', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/registrations/{$this->registration->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.level_review', LevelReview::APPROVED->value);

        $fresh = $this->registration->fresh();

        expect($fresh->level_review)->toBe(LevelReview::APPROVED)
            ->and($fresh->level_review_decided_by)->toBe($this->organizerUser->id)
            ->and($fresh->level_review_decided_at)->not->toBeNull();
    });

    it('aceita motivo opcional', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/registrations/{$this->registration->id}/approve", [
                'reason' => 'Jogador conhecido, nível compatível na prática.',
            ])
            ->assertOk()
            ->assertJsonPath('data.level_review_reason', 'Jogador conhecido, nível compatível na prática.');
    });

    /*
     * Trava de duplo clique: só revisão pendente aceita decisão. Sobrescrever
     * apagaria quem decidiu primeiro — e o aditivo §17 manda registrar o autor.
     */
    it('recusa decidir duas vezes, com 409', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/registrations/{$this->registration->id}/approve")
            ->assertOk();

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/registrations/{$this->registration->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REGISTRATION_LEVEL_REVIEW_NOT_PENDING');
    });

    it('recusa decidir inscrição que não precisa de análise, com 409', function (): void {
        $normal = Registration::factory()->forEvent($this->event)->create();

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/registrations/{$normal->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error.details.current_level_review', LevelReview::NOT_REQUIRED->value);
    });
});

describe('POST /api/v1/organizer/registrations/{id}/reject', function (): void {

    it('reprova registrando a decisão', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/registrations/{$this->registration->id}/reject", [
                'reason' => 'Categoria intermediária, atleta Open.',
            ])
            ->assertOk()
            ->assertJsonPath('data.level_review', LevelReview::REJECTED->value)
            ->assertJsonPath('data.level_review_reason', 'Categoria intermediária, atleta Open.');
    });

    /*
     * O aditivo §18 é explícito: "não excluir historicamente a inscrição".
     * Reprovar registra uma decisão; quem cancela é o atleta, pelo fluxo de
     * troca de dupla ou de cancelamento.
     */
    it('NÃO cancela a inscrição ao reprovar', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/registrations/{$this->registration->id}/reject")
            ->assertOk();

        $fresh = $this->registration->fresh();

        expect($fresh->status)->toBe(RegistrationStatus::PENDING_PAYMENT)
            ->and($fresh->cancelled_at)->toBeNull();
    });

    /*
     * E não devolve a vaga: liberar aqui deixaria o atleta que pagou sem vaga e
     * sem cancelamento — pior dos dois mundos.
     */
    it('não libera a vaga ao reprovar', function (): void {
        $before = $this->event->fresh()->teams_registered_count;

        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/registrations/{$this->registration->id}/reject")
            ->assertOk();

        expect($this->event->fresh()->teams_registered_count)->toBe($before);
    });

    it('audita a decisão com nível do atleta e do evento', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson("/api/v1/organizer/registrations/{$this->registration->id}/reject")
            ->assertOk();

        $log = AuditLog::query()
            ->where('action', AuditAction::ADMIN_ACTION->value)
            ->sole();

        expect($log->actor_id)->toBe($this->organizerUser->id)
            ->and($log->metadata['operation'])->toBe('REGISTRATION_LEVEL_REVIEW')
            ->and($log->metadata['decision'])->toBe(LevelReview::REJECTED->value)
            ->and($log->metadata['player_level'])->toBe(PlayerLevel::OPEN->value)
            ->and($log->metadata['event_level'])->toBe(LevelCategory::INTERMEDIATE->value);
    });
});

describe('análise de nível — autorização', function (): void {

    it('nega anônimo com 401', function (): void {
        $this->postJson("/api/v1/organizer/registrations/{$this->registration->id}/approve")
            ->assertUnauthorized();
    });

    /*
     * O atleta não aprova o próprio nível: seria o cliente decidindo regra de
     * negócio sobre si mesmo (CLAUDE.md §27.7).
     */
    it('nega o próprio atleta aprovar a própria inscrição com 403', function (): void {
        $this->actingAs($this->athlete)
            ->postJson("/api/v1/organizer/registrations/{$this->registration->id}/approve")
            ->assertForbidden();
    });

    /*
     * IDOR: outro organizador conhece o id da inscrição e tenta decidir. A
     * cadeia user → organizer → event → registration tem de barrar.
     */
    it('nega organizador de outro evento decidir (IDOR)', function (): void {
        $intruderUser = User::factory()->organizer()->create();
        Organizer::factory()->forUser($intruderUser)->create();

        $this->actingAs($intruderUser)
            ->postJson("/api/v1/organizer/registrations/{$this->registration->id}/approve")
            ->assertForbidden();

        expect($this->registration->fresh()->level_review)->toBe(LevelReview::REQUIRED);
    });

    it('permite super admin decidir, e audita', function (): void {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/organizer/registrations/{$this->registration->id}/approve")
            ->assertOk();

        expect($this->registration->fresh()->level_review_decided_by)->toBe($admin->id);
    });

    it('devolve 404 para inscrição inexistente', function (): void {
        $this->actingAs($this->organizerUser)
            ->postJson('/api/v1/organizer/registrations/01a03e01-0000-7000-8000-000000000000/approve')
            ->assertNotFound();
    });
});
