<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Domain\Enums\UserStatus;
use App\Modules\Users\Domain\Services\PhoneProtector;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Support\Facades\Hash;

function validPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ana Ribeiro',
        'email' => 'ana@saque.app',
        'password' => 'senha-forte-123',
        'password_confirmation' => 'senha-forte-123',
        'phone' => '(48) 99911-1234',
        'accept_terms' => true,
        'accept_privacy' => true,
    ], $overrides);
}

describe('POST /api/v1/auth/register', function () {
    it('cadastra usuário e devolve 201', function () {
        $response = $this->postJson('/api/v1/auth/register', validPayload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Ana Ribeiro')
            ->assertJsonPath('data.email', 'ana@saque.app')
            ->assertJsonPath('data.status', UserStatus::ACTIVE->value);

        expect(User::where('email', 'ana@saque.app')->exists())->toBeTrue();
    });

    it('atribui sempre o papel PLAYER', function () {
        $this->postJson('/api/v1/auth/register', validPayload())->assertCreated();

        $user = User::where('email', 'ana@saque.app')->firstOrFail()->load('roles');

        expect($user->roleList())->toBe([Role::PLAYER]);
    });

    it('IGNORA papel enviado pelo cliente — não existe escalada por payload', function () {
        $this->postJson('/api/v1/auth/register', validPayload([
            'role' => 'SUPER_ADMIN',
            'roles' => ['SUPER_ADMIN'],
            'status' => 'BLOCKED',
        ]))->assertCreated();

        $user = User::where('email', 'ana@saque.app')->firstOrFail()->load('roles');

        expect($user->roleList())->toBe([Role::PLAYER])
            ->and($user->isSuperAdmin())->toBeFalse()
            ->and($user->status)->toBe(UserStatus::ACTIVE);
    });

    it('nunca grava a senha em texto puro', function () {
        $this->postJson('/api/v1/auth/register', validPayload())->assertCreated();

        $user = User::where('email', 'ana@saque.app')->firstOrFail();

        expect($user->password)->not->toBe('senha-forte-123')
            ->and(Hash::check('senha-forte-123', $user->password))->toBeTrue();
    });

    it('nunca devolve senha nem hash de telefone na resposta', function () {
        $response = $this->postJson('/api/v1/auth/register', validPayload());

        $body = $response->json();

        expect(json_encode($body))
            ->not->toContain('senha-forte-123')
            ->not->toContain('phone_hash')
            ->not->toContain('password');
    });

    it('protege o telefone: grava hash e nunca usa o valor em claro como chave', function () {
        $this->postJson('/api/v1/auth/register', validPayload())->assertCreated();

        $expectedHash = app(PhoneProtector::class)->hash('(48) 99911-1234');

        // A busca acontece pelo hash, nunca pelo telefone em claro.
        $user = User::where('phone_hash', $expectedHash)->first();

        expect($user)->not->toBeNull()
            ->and($user->phone_hash)->toBe($expectedHash)
            // O valor decriptado volta normalizado em E.164.
            ->and($user->phone_encrypted)->toBe('+5548999111234');
    });

    it('deduplica telefone escrito de formas diferentes', function () {
        $this->postJson('/api/v1/auth/register', validPayload())->assertCreated();

        $response = $this->postJson('/api/v1/auth/register', validPayload([
            'email' => 'outra@saque.app',
            'phone' => '+55 48 99911-1234',   // mesmo número, outro formato
        ]));

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'PHONE_ALREADY_REGISTERED');
    });

    it('rejeita e-mail duplicado com 409', function () {
        $this->postJson('/api/v1/auth/register', validPayload())->assertCreated();

        $this->postJson('/api/v1/auth/register', validPayload(['phone' => '(41) 98888-4477']))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EMAIL_ALREADY_REGISTERED');
    });

    it('exige aceite dos termos e da privacidade', function (string $field) {
        $this->postJson('/api/v1/auth/register', validPayload([$field => false]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    })->with(['accept_terms', 'accept_privacy']);

    it('grava o consentimento com data e versão', function () {
        $this->postJson('/api/v1/auth/register', validPayload())->assertCreated();

        $user = User::where('email', 'ana@saque.app')->firstOrFail();

        expect($user->terms_accepted_at)->not->toBeNull()
            ->and($user->terms_version)->toBe(config('saque.terms_version'))
            ->and($user->privacy_accepted_at)->not->toBeNull()
            ->and($user->privacy_version)->toBe(config('saque.privacy_version'));
    });

    it('rejeita senha fraca', function () {
        $this->postJson('/api/v1/auth/register', validPayload([
            'password' => '123456',
            'password_confirmation' => '123456',
        ]))->assertStatus(422);
    });

    it('rejeita confirmação de senha divergente', function () {
        $this->postJson('/api/v1/auth/register', validPayload([
            'password_confirmation' => 'outra-senha-123',
        ]))->assertStatus(422);
    });

    it('não coleta CPF — o campo simplesmente não existe', function () {
        $this->postJson('/api/v1/auth/register', validPayload(['cpf' => '12345678900']))
            ->assertCreated();

        $columns = Schema::getColumnListing('users');

        expect($columns)->not->toContain('cpf')
            ->and($columns)->not->toContain('document');
    });

    it('registra a criação na trilha de auditoria', function () {
        $this->postJson('/api/v1/auth/register', validPayload())->assertCreated();

        $log = AuditLog::where('action', AuditAction::USER_CREATED)->first();

        expect($log)->not->toBeNull()
            ->and($log->target_type)->toBe('user');
    });

    it('nunca grava senha na trilha de auditoria', function () {
        $this->postJson('/api/v1/auth/register', validPayload())->assertCreated();

        $logs = AuditLog::all()->map->metadata->toJson();

        expect($logs)->not->toContain('senha-forte-123');
    });
});
