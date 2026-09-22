<?php

declare(strict_types=1);

use App\Modules\Finance\Application\LedgerWriter;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;

/*
 * `GET /organizer/finance` — o financeiro do próprio organizador.
 *
 * O teste que mais importa aqui é o de isolamento: o dinheiro de um organizador
 * não pode vazar para o painel de outro. Não existe parâmetro na rota, então a
 * prova é que dois organizadores com dados diferentes veem números diferentes.
 */

beforeEach(function (): void {
    $this->writer = app(LedgerWriter::class);

    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->create([
        'user_id' => $this->organizerUser->id,
        'name' => 'Arena Norte Beach',
    ]);
});

function ledgerFor(Organizer $organizer, int $gross = 13000, int $platformFee = 650, ?int $asaasFee = 199): Payment
{
    $payment = Payment::factory()->forOrganizer($organizer)->create([
        'status' => PaymentStatus::CONFIRMED,
        'confirmed_at' => CarbonImmutable::now(),
        'gross_cents' => $gross,
        'platform_fee_cents' => $platformFee,
        'platform_fee_basis_points' => 500,
        'asaas_fee_cents' => $asaasFee,
        'organizer_net_cents' => $asaasFee === null ? null : $gross - $platformFee - $asaasFee,
    ]);

    app(LedgerWriter::class)->recordConfirmedPayment($payment, CarbonImmutable::now());

    return $payment;
}

describe('GET /api/v1/organizer/finance', function () {
    it('recusa visitante anônimo com 401', function () {
        $this->getJson('/api/v1/organizer/finance')->assertStatus(401);
    });

    it('recusa conta sem perfil de organizador com 403', function () {
        $this->actingAs(User::factory()->player()->create())
            ->getJson('/api/v1/organizer/finance')
            ->assertStatus(403);
    });

    it('consolida o financeiro do organizador logado', function () {
        ledgerFor($this->organizer);

        $data = $this->actingAs($this->organizerUser)
            ->getJson('/api/v1/organizer/finance')
            ->assertOk()
            ->json('data');

        expect($data['organizer']['id'])->toBe($this->organizer->id)
            ->and($data['totals']['gross_cents'])->toBe(13000)
            ->and($data['totals']['platform_fee_cents'])->toBe(650)
            ->and($data['totals']['asaas_fee_cents'])->toBe(199)
            ->and($data['totals']['organizer_net_cents'])->toBe(12151)
            ->and($data['totals']['net_is_complete'])->toBeTrue()
            ->and($data['paid_count'])->toBe(1);
    });

    /*
     * IDOR sem parâmetro: a rota não aceita `organizer_id`, então a única forma
     * de o dinheiro alheio aparecer seria um filtro esquecido na query.
     */
    it('não mostra o dinheiro de outro organizador', function () {
        ledgerFor($this->organizer, gross: 13000);

        $outroUser = User::factory()->organizer()->create();
        $outro = Organizer::factory()->create(['user_id' => $outroUser->id]);
        ledgerFor($outro, gross: 50000, platformFee: 2500);

        $meu = $this->actingAs($this->organizerUser)
            ->getJson('/api/v1/organizer/finance')
            ->assertOk()
            ->json('data.totals.gross_cents');

        $dele = $this->actingAs($outroUser)
            ->getJson('/api/v1/organizer/finance')
            ->assertOk()
            ->json('data.totals.gross_cents');

        expect($meu)->toBe(13000)
            ->and($dele)->toBe(50000);
    });

    it('quebra por evento, do maior para o menor', function () {
        ledgerFor($this->organizer, gross: 10000, platformFee: 500);
        ledgerFor($this->organizer, gross: 30000, platformFee: 1500);

        $rows = $this->actingAs($this->organizerUser)
            ->getJson('/api/v1/organizer/finance')
            ->assertOk()
            ->json('data.by_event');

        expect($rows)->toHaveCount(2)
            ->and($rows[0]['gross_cents'])->toBe(30000)
            ->and($rows[1]['gross_cents'])->toBe(10000);
    });

    /*
     * Cobrança em aberto é expectativa, não receita: ela não tem lançamento no
     * ledger (§7.9) e viaja separada. Somá-la ao bruto apresentaria como
     * faturamento algo que talvez nunca seja pago.
     */
    it('separa cobranças pendentes do consolidado', function () {
        ledgerFor($this->organizer);

        Payment::factory()->forOrganizer($this->organizer)->create([
            'status' => PaymentStatus::PENDING,
            'gross_cents' => 9900,
        ]);

        $data = $this->actingAs($this->organizerUser)
            ->getJson('/api/v1/organizer/finance')
            ->assertOk()
            ->json('data');

        expect($data['pending']['count'])->toBe(1)
            ->and($data['pending']['gross_cents'])->toBe(9900)
            ->and($data['totals']['gross_cents'])->toBe(13000);
    });

    it('declara líquido incompleto quando falta a taxa do gateway', function () {
        ledgerFor($this->organizer, asaasFee: null);

        $totals = $this->actingAs($this->organizerUser)
            ->getJson('/api/v1/organizer/finance')
            ->assertOk()
            ->json('data.totals');

        expect($totals['net_is_complete'])->toBeFalse();
    });

    it('devolve zeros para organizador que ainda não faturou', function () {
        $totals = $this->actingAs($this->organizerUser)
            ->getJson('/api/v1/organizer/finance')
            ->assertOk()
            ->json('data.totals');

        expect($totals['gross_cents'])->toBe(0)
            ->and($totals['platform_fee_cents'])->toBe(0)
            ->and($totals['net_is_complete'])->toBeTrue();
    });
});
