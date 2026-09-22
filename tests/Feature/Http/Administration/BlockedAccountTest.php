<?php

declare(strict_types=1);

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Users\Domain\Enums\UserStatus;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * O bloqueio vale para a sessão JÁ ABERTA (ADR 0010 §3).
 *
 * Este arquivo cobre o buraco que a fatia encontrou: até aqui,
 * `UserStatus::BLOCKED` só era verificado em `AuthController::login()`. Quem já
 * estava logado quando foi suspenso continuava operando — criando evento, se
 * inscrevendo, pagando — até resolver deslogar.
 *
 * Com o painel entregando o botão de suspender, isso deixou de ser hipótese: a
 * suspensão precisa valer no request seguinte.
 */

describe('conta suspensa com sessão aberta', function () {
    it('recusa rota autenticada com 403 e código próprio', function () {
        $user = User::factory()->player()->create();

        // Sessão aberta ANTES da suspensão — é esse o cenário.
        $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

        $user->status = UserStatus::BLOCKED;
        $user->save();

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_BLOCKED');
    });

    it('impede o suspenso de se inscrever em evento', function () {
        $user = User::factory()->player()->blocked()->create();
        $event = Event::factory()
            ->registrationOpen()
            ->create();

        $this->actingAs($user)
            ->postJson("/api/v1/events/{$event->slug}/registrations", [
                'partner_mode' => 'INDIVIDUAL',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_BLOCKED');
    });

    it('mantém a vitrine pública acessível — bloqueio não é banimento da internet', function () {
        $user = User::factory()->player()->blocked()->create();

        Event::factory()->registrationOpen()->create();

        // Rota pública continua respondendo: o bloqueio impede operar, não ler
        // o que qualquer visitante anônimo já lê.
        $this->actingAs($user)->getJson('/api/v1/events')->assertOk();
    });

    it('bloqueio pelo painel derruba a sessão em curso', function () {
        $admin = User::factory()->superAdmin()->create();
        $victim = User::factory()->player()->create();

        $this->actingAs($victim)->getJson('/api/v1/me')->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$victim->id}/block", [
                'reason' => 'Conta usada para revenda de vagas.',
            ])
            ->assertOk();

        /*
         * `fresh()` de propósito: `actingAs()` injeta o objeto que está em
         * memória, e o `$victim` daqui foi carregado ANTES do bloqueio. Em
         * produção não existe esse atalho — o guard de sessão recarrega o
         * usuário do banco a cada request, e é o estado do banco que decide.
         */
        $this->actingAs($victim->fresh())
            ->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_BLOCKED');
    });
});
