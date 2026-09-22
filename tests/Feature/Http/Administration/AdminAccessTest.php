<?php

declare(strict_types=1);

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Barreira do painel administrativo (CLAUDE.md §10, §22 e §28).
 *
 * O §28 é explícito: "nenhuma rota administrativa depende de localStorage,
 * papel do frontend, botão escondido ou URL secreta". O teste correspondente é
 * este — a rota existe, é conhecida, e mesmo assim recusa quem não é super
 * admin.
 *
 * Cobre TODAS as rotas do grupo, e não uma amostra: a falha que este arquivo
 * previne é a rota nova registrada fora do middleware, que só aparece quando
 * alguém percorre a lista inteira.
 */

/** @return list<array{string, string}> */
function adminRoutes(): array
{
    return [
        ['GET', '/api/v1/admin/overview'],
        ['GET', '/api/v1/admin/users'],
        ['GET', '/api/v1/admin/organizers'],
        ['GET', '/api/v1/admin/events'],
        ['GET', '/api/v1/admin/payments'],
        ['GET', '/api/v1/admin/finance'],
        ['GET', '/api/v1/admin/audit-logs'],
    ];
}

describe('acesso ao painel administrativo', function () {
    it('recusa visitante anônimo com 401', function (string $method, string $uri) {
        $this->json($method, $uri)->assertStatus(401);
    })->with(adminRoutes());

    it('recusa jogador com 403', function (string $method, string $uri) {
        $this->actingAs(User::factory()->player()->create())
            ->json($method, $uri)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    })->with(adminRoutes());

    it('recusa organizador com 403', function (string $method, string $uri) {
        $organizerUser = User::factory()->organizer()->create();
        Organizer::factory()->create(['user_id' => $organizerUser->id]);

        $this->actingAs($organizerUser)
            ->json($method, $uri)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    })->with(adminRoutes());

    it('permite super admin', function (string $method, string $uri) {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->json($method, $uri)
            ->assertOk();
    })->with(adminRoutes());
});

describe('ações administrativas de escrita', function () {
    it('recusa jogador que tenta bloquear conta', function () {
        $victim = User::factory()->player()->create();

        $this->actingAs(User::factory()->player()->create())
            ->postJson("/api/v1/admin/users/{$victim->id}/block", [
                'reason' => 'tentativa de escalada de privilégio',
            ])
            ->assertStatus(403);

        // O que importa não é o status: é que nada mudou.
        expect($victim->fresh()->status->value)->toBe('ACTIVE');
    });

    it('recusa organizador que tenta cancelar evento de terceiro pela rota de admin', function () {
        $owner = User::factory()->organizer()->create();
        $organizer = Organizer::factory()->create(['user_id' => $owner->id]);
        $event = Event::factory()
            ->forOrganizer($organizer)
            ->published()
            ->create();

        $intruderUser = User::factory()->organizer()->create();
        Organizer::factory()->create(['user_id' => $intruderUser->id]);

        $this->actingAs($intruderUser)
            ->postJson("/api/v1/admin/events/{$event->slug}/cancel", [
                'reason' => 'cancelamento indevido por terceiro',
            ])
            ->assertStatus(403);

        expect($event->fresh()->status->value)->not->toBe('CANCELLED');
    });
});
