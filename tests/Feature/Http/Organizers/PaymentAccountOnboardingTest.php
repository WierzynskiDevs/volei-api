<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Organizers\Application\ReconcilePaymentAccountsAction;
use App\Modules\Organizers\Domain\Enums\PaymentAccountStatus;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Payments\Infrastructure\Providers\FakePaymentAccountProvider;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Onboarding da subconta Asaas (ADR 0018).
 */

afterEach(function (): void {
    FakePaymentAccountProvider::reset();
});

function paymentAccountPayload(array $overrides = []): array
{
    return array_merge([
        'mobile_phone' => '41999990000',
        'income_cents' => 500000,
        'address' => 'Rua das Palmeiras',
        'address_number' => '123',
        'province' => 'Centro',
        'postal_code' => '80000000',
    ], $overrides);
}

describe('POST /api/v1/organizer/payment-account', function (): void {
    it('abre a subconta e move para PENDING', function (): void {
        $user = User::factory()->organizer()->create();
        $organizer = Organizer::factory()->forUser($user)->withoutPaymentAccount()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/organizer/payment-account', paymentAccountPayload())
            ->assertStatus(202)
            ->assertJsonPath('data.payment_account_status', 'PENDING');

        $organizer->refresh();

        expect($organizer->payment_account_status)->toBe(PaymentAccountStatus::PENDING)
            ->and($organizer->payment_account_external_id)->not->toBeNull()
            ->and($organizer->payment_account_api_key_encrypted)->not->toBeNull()
            ->and($organizer->payment_account_wallet_id)->not->toBeNull()
            ->and($organizer->payment_account_address)->toBe('Rua das Palmeiras')
            ->and($organizer->payment_account_income_cents)->toBe(500000);
    });

    it('nunca devolve o apiKey nem o walletId da subconta na resposta', function (): void {
        $user = User::factory()->organizer()->create();
        Organizer::factory()->forUser($user)->withoutPaymentAccount()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/organizer/payment-account', paymentAccountPayload())
            ->assertStatus(202);

        expect($response->json('data'))->not->toHaveKey('payment_account_external_id')
            ->and($response->json('data'))->not->toHaveKey('api_key')
            ->and($response->json('data'))->not->toHaveKey('wallet_id');
    });

    it('audita o pedido sem nunca gravar apiKey/walletId em metadata', function (): void {
        $user = User::factory()->organizer()->create();
        $organizer = Organizer::factory()->forUser($user)->withoutPaymentAccount()->create();

        $this->actingAs($user)->postJson('/api/v1/organizer/payment-account', paymentAccountPayload());

        $log = AuditLog::query()
            ->where('action', AuditAction::ORGANIZER_PAYMENT_ACCOUNT_REQUESTED)
            ->where('target_id', $organizer->id)
            ->sole();

        expect(json_encode($log->metadata))
            ->not->toContain('fake_key_')
            ->and(json_encode($log->metadata))->not->toContain('fake_wallet_');
    });

    it('recusa pedir de novo quando já está PENDING ou LINKED — 409', function (): void {
        $user = User::factory()->organizer()->create();
        Organizer::factory()->forUser($user)->create(); // default: LINKED

        $this->actingAs($user)
            ->postJson('/api/v1/organizer/payment-account', paymentAccountPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PAYMENT_ACCOUNT_ALREADY_REQUESTED');
    });

    it('valida CEP e celular — 422', function (): void {
        $user = User::factory()->organizer()->create();
        Organizer::factory()->forUser($user)->withoutPaymentAccount()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/organizer/payment-account', paymentAccountPayload([
                'mobile_phone' => '123',
                'postal_code' => '123',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.mobile_phone.0', 'Informe o celular com DDD, só números.')
            ->assertJsonPath('error.details.postal_code.0', 'Informe o CEP com 8 dígitos, sem traço.');
    });

    it('nega anônimo com 401', function (): void {
        $this->postJson('/api/v1/organizer/payment-account', paymentAccountPayload())
            ->assertUnauthorized();
    });

    it('nega quem não é organizador com 403', function (): void {
        $athlete = User::factory()->create();

        $this->actingAs($athlete)
            ->postJson('/api/v1/organizer/payment-account', paymentAccountPayload())
            ->assertForbidden();
    });
});

describe('Reconciliação de subcontas (ADR 0018 §6)', function (): void {
    it('move para LINKED quando o gateway aprova', function (): void {
        $organizer = Organizer::factory()->withoutPaymentAccount()->create([
            'payment_account_status' => PaymentAccountStatus::PENDING,
            'payment_account_external_id' => 'fake_acc_test',
            'payment_account_api_key_encrypted' => 'fake_key_test',
        ]);

        FakePaymentAccountProvider::simulateApproval(true);

        $result = app(ReconcilePaymentAccountsAction::class)->execute();

        expect($result['linked'])->toBe(1);

        $organizer->refresh();
        expect($organizer->payment_account_status)->toBe(PaymentAccountStatus::LINKED);

        $log = AuditLog::query()
            ->where('action', AuditAction::ORGANIZER_PAYMENT_ACCOUNT_LINKED)
            ->where('target_id', $organizer->id)
            ->sole();
        expect($log->actor_id)->toBeNull();
    });

    it('mantém PENDING quando o gateway ainda não aprovou', function (): void {
        $organizer = Organizer::factory()->withoutPaymentAccount()->create([
            'payment_account_status' => PaymentAccountStatus::PENDING,
            'payment_account_external_id' => 'fake_acc_test',
            'payment_account_api_key_encrypted' => 'fake_key_test',
        ]);

        FakePaymentAccountProvider::simulateApproval(false);

        app(ReconcilePaymentAccountsAction::class)->execute();

        expect($organizer->fresh()->payment_account_status)->toBe(PaymentAccountStatus::PENDING);
    });

    it('ignora organizadores já LINKED ou NOT_LINKED', function (): void {
        Organizer::factory()->create(); // LINKED por padrão
        Organizer::factory()->withoutPaymentAccount()->create(); // NOT_LINKED

        FakePaymentAccountProvider::simulateApproval(true);

        $result = app(ReconcilePaymentAccountsAction::class)->execute();

        expect($result['checked'])->toBe(0)
            ->and($result['linked'])->toBe(0);
    });
});
