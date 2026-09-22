<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->user = User::factory()->player()->create([
        'email' => 'ana@saque.app',
        'password' => Hash::make('senha-forte-123'),
    ]);
});

describe('POST /api/v1/auth/login', function () {
    it('autentica com credenciais válidas', function () {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@saque.app',
            'password' => 'senha-forte-123',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $this->user->id)
            ->assertJsonPath('data.roles', [Role::PLAYER->value]);

        $this->assertAuthenticatedAs($this->user);
    });

    it('aceita e-mail com maiúsculas e espaços', function () {
        $this->postJson('/api/v1/auth/login', [
            'email' => '  ANA@SAQUE.APP  ',
            'password' => 'senha-forte-123',
        ])->assertOk();
    });

    it('rejeita senha errada com 401', function () {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@saque.app',
            'password' => 'senha-errada-999',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->assertGuest();
    });

    it('não revela se o e-mail existe — mesma resposta nos dois casos', function () {
        $existente = $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@saque.app',
            'password' => 'senha-errada-999',
        ]);

        $inexistente = $this->postJson('/api/v1/auth/login', [
            'email' => 'ninguem@saque.app',
            'password' => 'senha-errada-999',
        ]);

        expect($existente->status())->toBe($inexistente->status())
            ->and($existente->json('error.code'))->toBe($inexistente->json('error.code'))
            ->and($existente->json('error.message'))->toBe($inexistente->json('error.message'));
    });

    it('bloqueia conta suspensa mesmo com a senha correta', function () {
        $blocked = User::factory()->player()->blocked()->create([
            'email' => 'suspenso@saque.app',
            'password' => Hash::make('senha-forte-123'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'suspenso@saque.app',
            'password' => 'senha-forte-123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_BLOCKED');

        $this->assertGuest();
    });

    it('só revela o bloqueio depois de a senha conferir', function () {
        User::factory()->player()->blocked()->create([
            'email' => 'suspenso@saque.app',
            'password' => Hash::make('senha-forte-123'),
        ]);

        // Senha errada numa conta bloqueada não pode dizer que ela existe.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'suspenso@saque.app',
            'password' => 'chute-errado-123',
        ])->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    });

    it('registra o login na trilha de auditoria', function () {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@saque.app',
            'password' => 'senha-forte-123',
        ])->assertOk();

        expect(AuditLog::where('action', AuditAction::USER_LOGGED_IN)->exists())->toBeTrue();
    });

    it('nunca devolve a senha na resposta', function () {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@saque.app',
            'password' => 'senha-forte-123',
        ]);

        expect(json_encode($response->json()))->not->toContain('senha-forte-123');
    });

    it('aplica rate limiting após tentativas repetidas', function () {
        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'ana@saque.app',
                'password' => 'errada',
            ]);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@saque.app',
            'password' => 'errada',
        ])->assertStatus(429);
    });
});

describe('GET /api/v1/me', function () {
    it('exige autenticação', function () {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    });

    it('devolve o usuário autenticado com seus papéis', function () {
        $this->actingAs($this->user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $this->user->id)
            ->assertJsonPath('data.email', 'ana@saque.app')
            ->assertJsonPath('data.roles', [Role::PLAYER->value]);
    });

    it('mostra dados pessoais apenas ao próprio titular', function () {
        $this->user->update(['phone_encrypted' => '+5548999111234']);

        $response = $this->actingAs($this->user)->getJson('/api/v1/me');

        // O titular vê o próprio telefone completo.
        $response->assertOk()->assertJsonPath('data.phone', '+5548999111234');
    });
});

describe('POST /api/v1/auth/logout', function () {
    it('exige autenticação', function () {
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
    });

    /*
     * Prova da afirmação central do ADR 0005: o logout invalida a sessão NO
     * SERVIDOR. O teste captura o cookie emitido no login ("rouba" a sessão),
     * faz logout e tenta reusar aquele cookie — que precisa falhar.
     *
     * `forgetGuards()` é obrigatório entre as requisições: o container de teste
     * é reaproveitado e mantém o usuário em cache no guard, de modo que uma
     * requisição seguinte passaria na autenticação SEM sequer ler a sessão.
     * Sem esta limpeza o teste passaria mesmo com o logout quebrado.
     */
    it('encerra a sessão no servidor — cookie anterior ao logout deixa de valer', function () {
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@saque.app',
            'password' => 'senha-forte-123',
        ])->assertOk();

        $cookieName = (string) config('session.cookie');
        $stolen = null;

        foreach ($login->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $cookieName) {
                $stolen = $cookie->getValue();
            }
        }

        expect($stolen)->not->toBeNull();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->withUnencryptedCookie($cookieName, $stolen)
            ->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    });

    it('registra o logout na trilha de auditoria', function () {
        $this->actingAs($this->user)->postJson('/api/v1/auth/logout')->assertNoContent();

        expect(AuditLog::where('action', AuditAction::USER_LOGGED_OUT)->exists())->toBeTrue();
    });
});
