<?php

declare(strict_types=1);

use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Organizers\Infrastructure\Models\Plan;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Support\Str;

/*
 * A Definição de Pronto da Fase 1 (CLAUDE.md §28), percorrida inteira pela API.
 *
 * `PaymentFlowTest` já cobre pagamento, webhook e ledger a fundo — mas para no
 * ledger. Este arquivo cobre o elo seguinte, que é o que a fatia de remoção do
 * mock criou: **depois que o webhook confirma, os painéis mostram o dinheiro**.
 *
 * É o teste que atravessa todas as fronteiras de módulo de uma vez: Events →
 * Registrations → Payments → Finance → Administration. Cada uma tem teste
 * próprio; nenhum deles prova que a costura entre elas fecha.
 *
 * Roda contra o `FakePaymentProvider`: teste financeiro nunca depende de rede
 * (ADR 0004).
 */

beforeEach(function (): void {
    /*
     * `onPlan('FREE')` cria o plano se ele não existir: `RefreshDatabase` limpa
     * a base e o `PlanSeeder` não roda na suíte. O FREE é 5% + R$ 0 — o exemplo
     * do brief: R$ 130 gera R$ 6,50 de taxa.
     */
    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->onPlan('FREE')->create();

    Plan::query()->where('code', 'FREE')->update([
        'platform_fee_basis_points' => 500,
        'platform_fee_fixed_cents' => 0,
    ]);

    $this->athlete = User::factory()->player()->create();
    $this->admin = User::factory()->superAdmin()->create();
});

/** O payload que a tela de criação de evento envia. */
function journeyEventPayload(): array
{
    return [
        'name' => 'Copa Areia de Verão',
        'venue_name' => 'Arena Norte Beach',
        'city' => 'Florianópolis',
        'state' => 'SC',
        'date' => now()->addMonth()->toDateString(),
        'start_time' => '08:30',
        'end_time' => '18:00',
        'registration_close_at' => now()->addWeeks(3)->format('Y-m-d H:i'),
        'registration_fee_cents' => 13000,
        'max_teams' => 16,
        'courts' => 4,
        'min_games' => 3,
        'modality' => 'TWO_VS_TWO',
        'gender_category' => 'MALE',
        'level_category' => 'FREE',
        'event_type' => 'RANKING',
        'rules' => ['Melhor de 3 sets.'],
    ];
}

it('percorre a Fase 1 inteira: criar, publicar, inscrever, pagar, confirmar e ver nos painéis', function (): void {
    /* ---------------------------------------------------------------- *
     * 1. Organizador cria o evento e publica
     * ---------------------------------------------------------------- */
    $slug = $this->actingAs($this->organizerUser)
        ->postJson('/api/v1/organizer/events', journeyEventPayload())
        ->assertCreated()
        ->json('data.slug');

    /*
     * Publicar dentro da janela de inscrição JÁ abre as inscrições — não é
     * preciso um segundo passo, e pedi-lo devolveria 409. O comportamento é do
     * `PublishEventAction`, e este teste o documenta.
     */
    $status = $this->actingAs($this->organizerUser)
        ->postJson("/api/v1/organizer/events/{$slug}/publish")
        ->assertOk()
        ->json('data.status');

    expect($status)->toBe('REGISTRATION_OPEN');

    // Publicado é visível na vitrine pública — sem sessão nenhuma.
    $vitrine = $this->getJson('/api/v1/events')->assertOk()->json('data');
    expect(collect($vitrine)->pluck('slug'))->toContain($slug);

    /* ---------------------------------------------------------------- *
     * 2. Atleta se inscreve
     * ---------------------------------------------------------------- */
    $registrationId = $this->actingAs($this->athlete)
        ->postJson("/api/v1/events/{$slug}/registrations", ['partner_mode' => 'INDIVIDUAL', 'accept_rules' => true])
        ->assertCreated()
        ->json('data.id');

    // Antes de pagar, a inscrição NÃO está confirmada (ADR 0003).
    $minhas = $this->actingAs($this->athlete)
        ->getJson('/api/v1/me/registrations')
        ->assertOk()
        ->json('data.0');

    expect($minhas['status'])->toBe('PENDING_PAYMENT')
        // Sem cobrança criada ainda: `null`, e não um pagamento de R$ 0.
        ->and($minhas['payment'])->toBeNull();

    /* ---------------------------------------------------------------- *
     * 3. Atleta paga — o checkout cria a cobrança
     * ---------------------------------------------------------------- */
    $payment = $this->actingAs($this->athlete)
        ->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson("/api/v1/registrations/{$registrationId}/payments", ['method' => 'PIX'])
        ->assertCreated()
        ->json('data');

    expect($payment['gross_cents'])->toBe(13000)
        // 5% de 13000 = 650, congelado na cobrança (§7.5).
        ->and($payment['platform_fee_cents'])->toBe(650)
        ->and($payment['status'])->toBe('PENDING')
        // A criação NÃO confirma nada: quem confirma é o gateway (§14).
        ->and($payment['confirms_registration'])->toBeFalse();

    // A cobrança agora aparece na tela do atleta.
    $comCobranca = $this->actingAs($this->athlete)
        ->getJson('/api/v1/me/registrations')
        ->assertOk()
        ->json('data.0.payment');

    expect($comCobranca['id'])->toBe($payment['id'])
        ->and($comCobranca['gross_cents'])->toBe(13000);

    /* ---------------------------------------------------------------- *
     * 4. O gateway confirma — e SÓ ISSO confirma a inscrição
     * ---------------------------------------------------------------- */
    $full = Payment::query()->findOrFail($payment['id']);

    $this->postJson('/api/v1/webhooks/asaas', FakePaymentProvider::webhookBody(
        eventType: 'PAYMENT_CONFIRMED',
        providerPaymentId: (string) $full->provider_payment_id,
        externalReference: $full->external_reference,
        eventId: 'evt_journey_confirm',
        valueCents: 13000,
    ))->assertOk();

    expect(
        $this->actingAs($this->athlete)->getJson('/api/v1/me/registrations')->json('data.0.status')
    )->toBe('CONFIRMED');

    /* ---------------------------------------------------------------- *
     * 5. O painel do ORGANIZADOR reflete
     * ---------------------------------------------------------------- */
    $finance = $this->actingAs($this->organizerUser)
        ->getJson('/api/v1/organizer/finance')
        ->assertOk()
        ->json('data');

    expect($finance['totals']['gross_cents'])->toBe(13000)
        ->and($finance['totals']['platform_fee_cents'])->toBe(650)
        ->and($finance['paid_count'])->toBe(1)
        // Nada pendente: a única cobrança foi paga.
        ->and($finance['pending']['count'])->toBe(0);

    $linhaDoEvento = collect($finance['by_event'])->firstWhere('event_slug', $slug);

    expect($linhaDoEvento['gross_cents'])->toBe(13000)
        ->and($linhaDoEvento['paid_count'])->toBe(1);

    // E a inscrição aparece na lista dele, com a cobrança paga.
    $inscritos = $this->actingAs($this->organizerUser)
        ->getJson('/api/v1/organizer/registrations?payment_group=paid')
        ->assertOk()
        ->json('data');

    expect($inscritos)->toHaveCount(1)
        ->and($inscritos[0]['player']['name'])->toBe($this->athlete->name);

    /* ---------------------------------------------------------------- *
     * 6. O painel do SUPER ADMIN reflete
     * ---------------------------------------------------------------- */
    $consolidado = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/finance')
        ->assertOk()
        ->json('data');

    expect($consolidado['platform']['gross_cents'])->toBe(13000)
        /*
         * §7.6, a conta que não pode errar: a receita do SaaS é SÓ a taxa da
         * plataforma. A taxa do gateway é custo do organizador.
         */
        ->and($consolidado['platform']['platform_revenue_cents'])->toBe(650);

    $porOrganizador = collect($consolidado['by_organizer'])
        ->firstWhere('organizer_id', $this->organizer->id);

    expect($porOrganizador['gross_cents'])->toBe(13000);

    // O dashboard global conta o pagamento.
    $overview = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->json('data');

    expect($overview['payments']['by_status']['CONFIRMED'] ?? 0)->toBe(1)
        ->and($overview['finance']['gross_cents'])->toBe(13000);

    /* ---------------------------------------------------------------- *
     * 7. A trilha registrou o caminho inteiro
     * ---------------------------------------------------------------- */
    $acoes = collect(
        $this->actingAs($this->admin)->getJson('/api/v1/admin/audit-logs')->assertOk()->json('data')
    )->pluck('action');

    expect($acoes)->toContain('EVENT_CREATED', 'EVENT_PUBLISHED', 'PAYMENT_CREATED', 'PAYMENT_CONFIRMED');
});

/*
 * O outro lado da §28: o dinheiro de um organizador não aparece no painel de
 * outro. O teste de unidade do endpoint já cobre; aqui a garantia é sobre o
 * fluxo real, com evento e pagamento criados pela API.
 */
it('mantém o dinheiro de cada organizador no painel dele', function (): void {
    $outroUser = User::factory()->organizer()->create();
    Organizer::factory()->forUser($outroUser)->onPlan('FREE')->create();

    $slug = $this->actingAs($this->organizerUser)
        ->postJson('/api/v1/organizer/events', journeyEventPayload())
        ->assertCreated()
        ->json('data.slug');

    $this->actingAs($this->organizerUser)->postJson("/api/v1/organizer/events/{$slug}/publish")->assertOk();

    $registrationId = $this->actingAs($this->athlete)
        ->postJson("/api/v1/events/{$slug}/registrations", ['partner_mode' => 'INDIVIDUAL', 'accept_rules' => true])
        ->assertCreated()
        ->json('data.id');

    $payment = $this->actingAs($this->athlete)
        ->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson("/api/v1/registrations/{$registrationId}/payments", ['method' => 'PIX'])
        ->assertCreated()
        ->json('data');

    $full = Payment::query()->findOrFail($payment['id']);

    $this->postJson('/api/v1/webhooks/asaas', FakePaymentProvider::webhookBody(
        eventType: 'PAYMENT_CONFIRMED',
        providerPaymentId: (string) $full->provider_payment_id,
        externalReference: $full->external_reference,
        eventId: 'evt_journey_isolation',
        valueCents: 13000,
    ))->assertOk();

    $meu = $this->actingAs($this->organizerUser)->getJson('/api/v1/organizer/finance')->json('data.totals.gross_cents');
    $dele = $this->actingAs($outroUser)->getJson('/api/v1/organizer/finance')->json('data.totals.gross_cents');

    expect($meu)->toBe(13000)
        ->and($dele)->toBe(0);
});
