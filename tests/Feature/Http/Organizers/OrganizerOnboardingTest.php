<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Organizers\Domain\Enums\DocumentType;
use App\Modules\Organizers\Domain\Services\DocumentProtector;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Organizers\Infrastructure\Models\Plan;
use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Onboarding do organizador (OPEN-QUESTIONS Q6, ADR 0014).
 *
 * Cobre o exigido por CLAUDE.md §22 para todo endpoint protegido: caminho
 * feliz, validação, 401 anônimo, e o conflito específico deste endpoint —
 * 409 quando já existe organizador (dono ou documento duplicado).
 */

function organizerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Arena Norte Beach',
        'description' => 'Eventos de vôlei de praia em Curitiba.',
        'contact_email' => 'contato@arenanorte.com.br',
        'city' => 'Curitiba',
        'state' => 'PR',
        'document_number' => '529.982.247-25',
    ], $overrides);
}

/*
 * RefreshDatabase não roda seeders (tests/Pest.php). `CreateOrganizerAction`
 * lê o plano padrão por `saque.default_plan_code` (FREE) — sem esta linha,
 * todo caminho feliz falha com 404 (NOT_FOUND do `firstOrFail()`), que é
 * ausência de dado de referência, não bug de rota.
 */
beforeEach(function (): void {
    Plan::query()->firstOrCreate(
        ['code' => 'FREE'],
        [
            'name' => 'FREE',
            'status' => 'ACTIVE',
            'monthly_price_cents' => 0,
            'platform_fee_basis_points' => 500,
            'platform_fee_fixed_cents' => 0,
            'event_limit' => 3,
            'registration_limit' => null,
            'features' => [],
            'sort_order' => 0,
        ],
    );
});

describe('POST /api/v1/organizers', function () {
    it('cria o perfil de organizador e devolve 201', function () {
        $user = User::factory()->player()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/organizers', organizerPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Arena Norte Beach')
            ->assertJsonPath('data.status', 'REGULAR')
            ->assertJsonPath('data.payment_account_status', 'NOT_LINKED')
            ->assertJsonPath('data.can_receive_payments', false);

        expect(Organizer::where('user_id', $user->id)->exists())->toBeTrue();
    });

    it('atribui o papel ORGANIZER sem remover os papéis existentes', function () {
        $user = User::factory()->player()->create();

        $this->actingAs($user)->postJson('/api/v1/organizers', organizerPayload())->assertCreated();

        $user->load('roles');

        expect($user->roleList())->toEqualCanonicalizing([Role::PLAYER, Role::ORGANIZER]);
    });

    it('protege o documento: grava hash e nunca usa o valor em claro como chave', function () {
        $user = User::factory()->player()->create();

        $this->actingAs($user)->postJson('/api/v1/organizers', organizerPayload())->assertCreated();

        $expectedHash = app(DocumentProtector::class)->hash('529.982.247-25');

        $organizer = Organizer::where('document_number_hash', $expectedHash)->first();

        expect($organizer)->not->toBeNull()
            ->and($organizer->document_type)->toBe(DocumentType::CPF)
            ->and($organizer->document_number_encrypted)->toBe('52998224725');
    });

    it('nunca devolve o documento na resposta', function () {
        $user = User::factory()->player()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/organizers', organizerPayload());

        expect(json_encode($response->json()))
            ->not->toContain('52998224725')
            ->not->toContain('document_number');
    });

    it('nunca grava o documento em claro na auditoria', function () {
        $user = User::factory()->player()->create();

        $this->actingAs($user)->postJson('/api/v1/organizers', organizerPayload())->assertCreated();

        $log = AuditLog::where('action', AuditAction::ORGANIZER_CREATED)->firstOrFail();

        expect(json_encode($log->metadata))->not->toContain('52998224725')
            ->and($log->metadata['document_type'])->toBe('CPF');
    });

    it('aceita CNPJ também', function () {
        $user = User::factory()->player()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/organizers', organizerPayload(['document_number' => '11.222.333/0001-81']))
            ->assertCreated();

        $organizer = Organizer::where('user_id', $user->id)->firstOrFail();

        expect($organizer->document_type)->toBe(DocumentType::CNPJ);
    });

    it('rejeita anônimo com 401', function () {
        $this->postJson('/api/v1/organizers', organizerPayload())
            ->assertUnauthorized();
    });

    it('rejeita CPF/CNPJ com dígito verificador inválido — 422', function () {
        $user = User::factory()->player()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/organizers', organizerPayload(['document_number' => '111.444.777-00']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.document_number.0', 'Informe um CPF ou CNPJ válido.');
    });

    it('rejeita payload sem nome — 422', function () {
        $user = User::factory()->player()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/organizers', organizerPayload(['name' => '']))
            ->assertStatus(422);
    });

    it('recusa usuário que já é organizador — 409', function () {
        $user = User::factory()->organizer()->create();
        Organizer::factory()->forUser($user)->create();

        $this->actingAs($user)
            ->postJson('/api/v1/organizers', organizerPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ORGANIZER_ALREADY_EXISTS');
    });

    it('recusa documento já vinculado a outro organizador — 409', function () {
        $owner = User::factory()->organizer()->create();
        Organizer::factory()->forUser($owner)->create([
            'document_number_encrypted' => '52998224725',
            'document_number_hash' => app(DocumentProtector::class)->hash('52998224725'),
            'document_type' => DocumentType::CPF,
        ]);

        $newcomer = User::factory()->player()->create();

        $this->actingAs($newcomer)
            ->postJson('/api/v1/organizers', organizerPayload(['document_number' => '52998224725']))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ORGANIZER_DOCUMENT_ALREADY_LINKED');

        expect(Organizer::where('user_id', $newcomer->id)->exists())->toBeFalse();
    });
});
