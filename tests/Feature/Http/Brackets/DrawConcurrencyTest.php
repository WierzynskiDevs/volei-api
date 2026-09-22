<?php

declare(strict_types=1);

use App\Modules\Brackets\Application\StartDrawAction;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Concorrência no sorteio (CLAUDE.md §8/§22, ADR 0011).
 *
 * Mesma ressalva de tests/Feature/Http/Registrations/RegistrationConcurrencyTest.php:
 * a suíte roda dentro de uma transação, então não há paralelismo real aqui.
 * O que se prova é que o lock é de fato emitido e que o duplo clique
 * sequencial (iniciar/publicar duas vezes) devolve 409 em vez de duplicar
 * efeito — nunca dois conjuntos de posições para o mesmo evento.
 */

beforeEach(function (): void {
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create();
    $this->event = Event::factory()->registrationClosed()->forOrganizer($this->organizer)->create();

    confirmedTeam($this->event);
    confirmedTeam($this->event);
});

it('trava a linha do evento com SELECT ... FOR UPDATE ao iniciar o sorteio', function () {
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = mb_strtolower($query->sql);
    });

    app(StartDrawAction::class)->execute($this->event, $this->organizerUser);

    $locking = array_filter(
        $statements,
        fn (string $sql): bool => str_contains($sql, 'from "events"') && str_contains($sql, 'for update'),
    );

    expect($locking)->not->toBeEmpty('o início do sorteio precisa travar a linha do evento');
});

it('duplo clique em "iniciar sorteio" não cria dois conjuntos de posições', function () {
    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/start-draw")
        ->assertOk();

    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/start-draw")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DRAW_NOT_POSSIBLE');

    expect($this->event->fresh()->status)->toBe(EventStatus::AWAITING_DRAW);
});

it('duplo clique em "publicar chave" não reprocessa a publicação', function () {
    app(StartDrawAction::class)->execute($this->event, $this->organizerUser);

    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/publish")
        ->assertOk();

    $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$this->event->slug}/bracket/publish")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DRAW_NOT_EDITABLE');

    expect($this->event->fresh()->status)->toBe(EventStatus::BRACKET_PUBLISHED);
});
