<?php

declare(strict_types=1);

use App\Modules\Finance\Application\LedgerWriter;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Users\Infrastructure\Models\User;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;

/*
 * `/admin/financeiro`: consolidação lida do ledger.
 *
 * Os números aqui são os do §7: bruto 13000, taxa da plataforma 650 (5% do
 * bruto), taxa do gateway 199, líquido 12151. Se alguma dessas contas mudar sem
 * decisão, é aqui que aparece.
 */

beforeEach(function (): void {
    $this->admin = User::factory()->superAdmin()->create();
    $this->organizer = Organizer::factory()->create(['name' => 'Arena Norte Beach']);
    $this->writer = app(LedgerWriter::class);
});

function confirmedPaymentFor(Organizer $organizer, ?int $asaasFeeCents = 199): Payment
{
    $payment = Payment::factory()
        ->forOrganizer($organizer)
        ->create([
            'status' => PaymentStatus::CONFIRMED,
            'confirmed_at' => CarbonImmutable::now(),
            'gross_cents' => 13000,
            'platform_fee_cents' => 650,
            'platform_fee_basis_points' => 500,
            'asaas_fee_cents' => $asaasFeeCents,
            'organizer_net_cents' => $asaasFeeCents === null ? null : 13000 - 650 - $asaasFeeCents,
        ]);

    return $payment;
}

describe('GET /api/v1/admin/finance', function () {
    it('consolida bruto, receita da plataforma, taxa do gateway e líquido', function () {
        $payment = confirmedPaymentFor($this->organizer);
        $this->writer->recordConfirmedPayment($payment, CarbonImmutable::now());

        $platform = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/finance')
            ->assertOk()
            ->json('data.platform');

        expect($platform['gross_cents'])->toBe(13000)
            ->and($platform['platform_revenue_cents'])->toBe(650)
            ->and($platform['asaas_fee_cents'])->toBe(199)
            ->and($platform['organizer_net_cents'])->toBe(12151)
            ->and($platform['net_is_complete'])->toBeTrue();
    });

    /*
     * CLAUDE.md §7.6, e é o erro mais caro possível nesta tela: a taxa do
     * gateway é custo do organizador e NUNCA entra como receita do SaaS.
     * Somar as duas infla o faturamento com dinheiro que é do Asaas.
     */
    it('não conta a taxa do gateway como receita da plataforma', function () {
        $payment = confirmedPaymentFor($this->organizer);
        $this->writer->recordConfirmedPayment($payment, CarbonImmutable::now());

        $platform = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/finance')
            ->assertOk()
            ->json('data.platform');

        expect($platform['platform_revenue_cents'])->toBe(650)
            ->and($platform['platform_revenue_cents'])->not->toBe(650 + 199);
    });

    /*
     * ADR 0009 §5: enquanto o gateway não informa a taxa, o líquido é
     * DESCONHECIDO — que é diferente de zero. A tela precisa saber disso para
     * escrever "indisponível" em vez de um número que parece fechado.
     */
    it('declara líquido incompleto quando a taxa do gateway ainda não veio', function () {
        $payment = confirmedPaymentFor($this->organizer, asaasFeeCents: null);
        $this->writer->recordConfirmedPayment($payment, CarbonImmutable::now());

        $platform = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/finance')
            ->assertOk()
            ->json('data.platform');

        expect($platform['gross_cents'])->toBe(13000)
            ->and($platform['platform_revenue_cents'])->toBe(650)
            ->and($platform['net_is_complete'])->toBeFalse();
    });

    it('quebra o consolidado por organizador e por evento', function () {
        $payment = confirmedPaymentFor($this->organizer);
        $this->writer->recordConfirmedPayment($payment, CarbonImmutable::now());

        $data = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/finance')
            ->assertOk()
            ->json('data');

        $organizerRow = collect($data['by_organizer'])->firstWhere('organizer_id', $this->organizer->id);
        $eventRow = collect($data['by_event'])->firstWhere('event_id', $payment->event_id);

        expect($organizerRow['organizer_name'])->toBe('Arena Norte Beach')
            ->and($organizerRow['gross_cents'])->toBe(13000)
            ->and($organizerRow['platform_revenue_cents'])->toBe(650)
            ->and($eventRow['gross_cents'])->toBe(13000);
    });

    it('devolve zeros, e não erro, quando não há nada lançado', function () {
        $platform = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/finance')
            ->assertOk()
            ->json('data.platform');

        expect($platform['gross_cents'])->toBe(0)
            ->and($platform['platform_revenue_cents'])->toBe(0)
            ->and($platform['net_is_complete'])->toBeTrue();
    });

    /*
     * O estorno é lançamento NOVO no ledger (append-only, §9), e precisa
     * aparecer como saída. Somar `payments` em vez do ledger mostraria dinheiro
     * já devolvido como se ainda fosse receita — é a razão de o relatório ler o
     * ledger.
     */
    it('mostra estorno como saída no consolidado', function () {
        $payment = confirmedPaymentFor($this->organizer);
        $this->writer->recordConfirmedPayment($payment, CarbonImmutable::now());
        $this->writer->recordRefund(
            $payment,
            Money::fromCents(13000),
            CarbonImmutable::now(),
            'Evento cancelado pelo organizador.',
        );

        $platform = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/finance')
            ->assertOk()
            ->json('data.platform');

        expect($platform['refunded_cents'])->toBe(13000);
    });
});
