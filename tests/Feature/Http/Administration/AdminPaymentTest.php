<?php

declare(strict_types=1);

use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * `/admin/pagamentos`: leitura global de cobranças.
 */

beforeEach(function (): void {
    $this->admin = User::factory()->superAdmin()->create();
    $this->organizer = Organizer::factory()->create();
});

describe('GET /api/v1/admin/payments', function () {
    it('lista cobranças com pagador, evento e a decomposição congelada', function () {
        Payment::factory()->forOrganizer($this->organizer)->create([
            'gross_cents' => 13000,
            'platform_fee_cents' => 650,
        ]);

        $row = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/payments')
            ->assertOk()
            ->json('data.0');

        expect($row['gross_cents'])->toBe(13000)
            ->and($row['platform_fee_cents'])->toBe(650)
            ->and($row['payer'])->toHaveKeys(['id', 'name', 'email'])
            ->and($row['event'])->toHaveKeys(['id', 'slug', 'name']);
    });

    /*
     * As abas da tela são GRUPOS de status: "Pagos" precisa reunir CONFIRMED e
     * RECEIVED, que são fatos diferentes (ADR 0009 §2) e aparecem juntos.
     */
    it('filtra pelo grupo "pagos", que reúne confirmado e recebido', function () {
        Payment::factory()->forOrganizer($this->organizer)->create([
            'status' => PaymentStatus::CONFIRMED,
            'confirmed_at' => CarbonImmutable::now(),
        ]);
        Payment::factory()->forOrganizer($this->organizer)->create([
            'status' => PaymentStatus::RECEIVED,
            'confirmed_at' => CarbonImmutable::now(),
            'received_at' => CarbonImmutable::now(),
        ]);
        Payment::factory()->forOrganizer($this->organizer)->create(['status' => PaymentStatus::PENDING]);

        $statuses = collect(
            $this->actingAs($this->admin)
                ->getJson('/api/v1/admin/payments?group=paid')
                ->assertOk()
                ->json('data')
        )->pluck('status')->all();

        expect($statuses)->toHaveCount(2)
            ->and($statuses)->toContain(PaymentStatus::CONFIRMED->value, PaymentStatus::RECEIVED->value)
            ->and($statuses)->not->toContain(PaymentStatus::PENDING->value);
    });

    it('trata grupo desconhecido como ausência de filtro, não como lista vazia', function () {
        Payment::factory()->count(2)->forOrganizer($this->organizer)->create();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/payments?group=inexistente')
            ->assertOk();

        expect($response->json('data'))->toHaveCount(2);
    });

    it('devolve nulo, e não zero, para líquido ainda desconhecido', function () {
        Payment::factory()->forOrganizer($this->organizer)->create([
            'asaas_fee_cents' => null,
            'organizer_net_cents' => null,
        ]);

        $row = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/payments')
            ->assertOk()
            ->json('data.0');

        expect($row['asaas_fee_cents'])->toBeNull()
            ->and($row['organizer_net_cents'])->toBeNull();
    });
});

describe('GET /api/v1/admin/payments/{payment}', function () {
    it('devolve o detalhe da cobrança', function () {
        $payment = Payment::factory()->forOrganizer($this->organizer)->create();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $payment->id);
    });

    it('devolve 404 para cobrança inexistente', function () {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/payments/'.Str::uuid7()->toString())
            ->assertStatus(404);
    });
});

/*
 * CLAUDE.md §14: a confirmação nunca vem de decisão humana na interface. Um
 * botão "marcar como pago" no painel seria exatamente a confirmação visual que
 * o contrato proíbe — então a rota não existe, e este teste é o que impede
 * alguém de acrescentá-la sem discussão.
 */
it('não expõe nenhuma rota administrativa que altere status de pagamento', function () {
    $payment = Payment::factory()->forOrganizer($this->organizer)->create();

    foreach (['confirm', 'status', 'receive', 'refund'] as $verb) {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/payments/{$payment->id}/{$verb}", [])
            ->assertStatus(404);
    }

    expect($payment->fresh()->status)->toBe(PaymentStatus::PENDING);
});
