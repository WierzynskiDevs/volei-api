<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Domain\Enums\UserStatus;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * `/admin/usuarios`: listagem com filtros e suspensão de conta.
 */

beforeEach(function (): void {
    $this->admin = User::factory()->superAdmin()->create(['name' => 'Admin da Plataforma']);
});

describe('GET /api/v1/admin/users', function () {
    it('lista contas com papéis e situação', function () {
        User::factory()->player()->create(['name' => 'Ana Jogadora']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users')
            ->assertOk();

        expect($response->json('data'))->not->toBeEmpty();
        expect($response->json('data.0'))->toHaveKeys(['id', 'name', 'email', 'status', 'roles']);
    });

    it('filtra por papel', function () {
        User::factory()->player()->create();
        $organizerUser = User::factory()->organizer()->create();
        Organizer::factory()->create(['user_id' => $organizerUser->id]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users?filter=organizer')
            ->assertOk();

        $roles = collect($response->json('data'))->pluck('roles')->flatten()->unique()->all();

        expect($roles)->toContain(Role::ORGANIZER->value)
            ->and($roles)->not->toContain(Role::PLAYER->value);
    });

    it('filtra por situação', function () {
        User::factory()->player()->blocked()->create(['name' => 'Rafael Suspenso']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users?filter=blocked')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();

        expect($names)->toContain('Rafael Suspenso');
    });

    it('busca por nome e por e-mail', function () {
        User::factory()->player()->create(['name' => 'Marina Souza', 'email' => 'marina@saque.app']);
        User::factory()->player()->create(['name' => 'João Lima', 'email' => 'joao@saque.app']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users?q=marina')
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.name'))->toBe('Marina Souza');
    });

    /*
     * LGPD §12. A listagem identifica a conta pelo e-mail — que a tela mostra —
     * mas o telefone só sai mascarado, e a máscara é feita no servidor.
     * Devolver o telefone completo de toda a base para preencher uma coluna
     * mascarada seria minimização ao contrário.
     */
    it('nunca devolve telefone completo na listagem', function () {
        User::factory()->player()->create(['name' => 'Com Telefone']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('name', 'Com Telefone');

        expect($row)->not->toHaveKey('phone');

        if ($row['phone_masked'] !== null) {
            expect($row['phone_masked'])->toContain('•');
        }
    });
});

describe('POST /api/v1/admin/users/{user}/block', function () {
    it('suspende a conta e registra a trilha com o motivo', function () {
        $victim = User::factory()->player()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$victim->id}/block", [
                'reason' => 'Uso de conta para revenda de vagas.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', UserStatus::BLOCKED->value);

        expect($victim->fresh()->status)->toBe(UserStatus::BLOCKED);

        $log = AuditLog::query()
            ->where('action', AuditAction::USER_BLOCKED->value)
            ->where('target_id', $victim->id)
            ->firstOrFail();

        expect($log->actor_id)->toBe($this->admin->id)
            ->and($log->metadata['reason'])->toBe('Uso de conta para revenda de vagas.')
            ->and($log->metadata['from'])->toBe('ACTIVE')
            ->and($log->metadata['to'])->toBe('BLOCKED');
    });

    it('exige justificativa com 422', function () {
        $victim = User::factory()->player()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$victim->id}/block", ['reason' => 'spam'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        expect($victim->fresh()->status)->toBe(UserStatus::ACTIVE);
    });

    /*
     * ADR 0010 §6: sem esta guarda, um clique errado tranca o único acesso
     * global da plataforma e não há caminho de volta pela aplicação.
     */
    it('recusa que o admin bloqueie a própria conta com 409', function () {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->admin->id}/block", [
                'reason' => 'tentativa de bloquear a si mesmo',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ADMIN_CANNOT_BLOCK_SELF');

        expect($this->admin->fresh()->status)->toBe(UserStatus::ACTIVE);
    });

    it('não duplica trilha quando a conta já está suspensa', function () {
        $victim = User::factory()->player()->blocked()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$victim->id}/block", [
                'reason' => 'repetição do mesmo bloqueio',
            ])
            ->assertOk();

        expect(AuditLog::query()->where('action', AuditAction::USER_BLOCKED->value)->count())->toBe(0);
    });
});

describe('POST /api/v1/admin/users/{user}/unblock', function () {
    it('reativa a conta e registra a trilha', function () {
        $victim = User::factory()->player()->blocked()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$victim->id}/unblock", [
                'reason' => 'Denúncia apurada e considerada improcedente.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', UserStatus::ACTIVE->value);

        expect($victim->fresh()->status)->toBe(UserStatus::ACTIVE);

        expect(
            AuditLog::query()
                ->where('action', AuditAction::USER_UNBLOCKED->value)
                ->where('target_id', $victim->id)
                ->exists()
        )->toBeTrue();
    });
});
