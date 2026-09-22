<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Finance\Domain\Enums\LedgerEntryType;
use App\Modules\Finance\Infrastructure\Models\LedgerEntry;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Organizers\Infrastructure\Models\Plan;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Enums\WebhookProcessingStatus;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Payments\Infrastructure\Models\PaymentWebhookEvent;
use App\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use App\Modules\Registrations\Domain\Enums\RegistrationStatus;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Fluxo ponta a ponta de pagamento (CLAUDE.md §22: teste de integração).
 *
 * Roda contra o `FakePaymentProvider` — teste financeiro nunca depende de rede
 * (ADR 0004). O fake simula os dois estágios do Asaas (`CONFIRMED` e depois
 * `RECEIVED`) e a reentrega do mesmo evento.
 */

beforeEach(function (): void {
    // Plano com 5% + R$ 0: o exemplo do brief (R$ 130 → R$ 6,50 de taxa).
    $this->plan = Plan::query()->where('code', 'FREE')->first()
        ?? Plan::query()->create([
            'code' => 'FREE',
            'name' => 'Gratuito',
            'platform_fee_basis_points' => 500,
            'platform_fee_fixed_cents' => 0,
            'monthly_price_cents' => 0,
        ]);

    $this->plan->update(['platform_fee_basis_points' => 500, 'platform_fee_fixed_cents' => 0]);

    $this->organizerUser = User::factory()->organizer()->create();
    $this->organizer = Organizer::factory()->forUser($this->organizerUser)->create([
        'plan_id' => $this->plan->id,
    ]);

    $this->event = Event::factory()
        ->forOrganizer($this->organizer)
        ->registrationOpen()
        ->create([
            'level_category' => LevelCategory::FREE,
            'registration_fee_cents' => 13000,
            'max_teams' => 16,
        ]);

    $this->athlete = User::factory()->player()->create();

    $this->registration = Registration::factory()
        ->forEvent($this->event)
        ->forUser($this->athlete)
        ->create();
});

/** Cria a cobrança pela API, como o checkout faz. */
function createCharge(mixed $test, ?string $key = null, string $method = 'PIX'): TestResponse
{
    return $test
        ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid7())
        ->postJson("/api/v1/registrations/{$test->registration->id}/payments", ['method' => $method]);
}

describe('POST /api/v1/registrations/{id}/payments', function (): void {

    it('cria a cobrança com a taxa da plataforma congelada', function (): void {
        $response = createCharge($this->actingAs($this->athlete))
            ->assertCreated()
            ->assertJsonPath('data.gross_cents', 13000)
            // 5% de R$ 130,00 = R$ 6,50 (BRIEF §29).
            ->assertJsonPath('data.platform_fee_cents', 650)
            ->assertJsonPath('data.platform_fee_basis_points', 500)
            ->assertJsonPath('data.status', PaymentStatus::PENDING->value);

        /*
         * Taxa do gateway e líquido nascem NULOS — só o Asaas sabe
         * (ADR 0009 §5). `null` é "desconhecido", não zero.
         */
        expect($response->json('data.asaas_fee_cents'))->toBeNull()
            ->and($response->json('data.organizer_net_cents'))->toBeNull()
            ->and($response->json('data.money_is_available'))->toBeFalse();
    });

    it('grava o id do gateway e a referência externa', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();

        $payment = Payment::query()->sole();

        expect($payment->provider_payment_id)->not->toBeNull()
            ->and($payment->external_reference)->not->toBeNull()
            ->and($payment->provider)->toBe('fake');
    });

    /*
     * Idempotência (CLAUDE.md §8): "chave repetida retorna a MESMA resposta,
     * sem criar novo efeito". É o que impede duplo clique no checkout de gerar
     * duas cobranças.
     */
    it('chave de idempotência repetida devolve a mesma cobrança', function (): void {
        $key = (string) Str::uuid7();

        $first = createCharge($this->actingAs($this->athlete), $key)->assertCreated();
        $second = createCharge($this->actingAs($this->athlete), $key)->assertCreated();

        expect($second->json('data.id'))->toBe($first->json('data.id'))
            ->and(Payment::query()->count())->toBe(1);
    });

    it('exige o header Idempotency-Key', function (): void {
        $this->actingAs($this->athlete)
            ->postJson("/api/v1/registrations/{$this->registration->id}/payments", ['method' => 'PIX'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    });

    it('recusa cobrança de evento gratuito com 409', function (): void {
        $free = Event::factory()->forOrganizer($this->organizer)->registrationOpen()->free()->create([
            'level_category' => LevelCategory::FREE,
        ]);
        $registration = Registration::factory()->forEvent($free)->forUser($this->athlete)->create();

        $this->actingAs($this->athlete)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/registrations/{$registration->id}/payments", ['method' => 'PIX'])
            ->assertStatus(409)
            ->assertJsonPath('error.details.reason', 'free_event');
    });

    it('recusa cobrança de inscrição já confirmada com 409', function (): void {
        // `status` e `confirmed_at` não são fillable de propósito (CLAUDE.md §5):
        // são consequência de ação, nunca de formulário.
        $this->registration->status = RegistrationStatus::CONFIRMED;
        $this->registration->confirmed_at = CarbonImmutable::now();
        $this->registration->save();

        createCharge($this->actingAs($this->athlete))
            ->assertStatus(409)
            ->assertJsonPath('error.details.reason', 'registration_already_confirmed');
    });

    it('nega anônimo com 401', function (): void {
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/registrations/{$this->registration->id}/payments", ['method' => 'PIX'])
            ->assertUnauthorized();
    });

    /*
     * IDOR: a cobrança é nominal ao pagador (ADR 0001). Nem o organizador paga
     * pelo atleta — criaria cobrança no nome de quem não pediu.
     */
    it('nega outro usuário criar cobrança para inscrição de terceiro (IDOR)', function (): void {
        $intruder = User::factory()->player()->create();

        $this->actingAs($intruder)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/registrations/{$this->registration->id}/payments", ['method' => 'PIX'])
            ->assertForbidden();

        expect(Payment::query()->count())->toBe(0);
    });

    it('audita PAYMENT_CREATED com a taxa congelada', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();

        $log = AuditLog::query()->where('action', AuditAction::PAYMENT_CREATED->value)->sole();

        expect($log->metadata['gross_cents'])->toBe(13000)
            ->and($log->metadata['platform_fee_cents'])->toBe(650)
            ->and($log->metadata['platform_fee_basis_points'])->toBe(500);
    });
});

describe('webhook — confirmação em dois estágios (ADR 0009 §2)', function (): void {

    /*
     * O coração do S6. `CONFIRMED` confirma a INSCRIÇÃO mas o dinheiro ainda
     * NÃO está disponível; `RECEIVED` é que libera. No cartão a distância entre
     * os dois é de 32 dias — fundir os dois mostraria como disponível dinheiro
     * que o gateway não liberou.
     */
    it('CONFIRMED confirma a inscrição mas NÃO libera o dinheiro', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        $this->postJson('/api/v1/webhooks/asaas', FakePaymentProvider::webhookBody(
            eventType: 'PAYMENT_CONFIRMED',
            providerPaymentId: (string) $payment->provider_payment_id,
            externalReference: $payment->external_reference,
            eventId: 'evt_confirm_1',
            valueCents: 13000,
        ))->assertOk();

        $payment->refresh();

        expect($payment->status)->toBe(PaymentStatus::CONFIRMED)
            ->and($payment->status->confirmsRegistration())->toBeTrue()
            // Dinheiro NÃO disponível ainda.
            ->and($payment->status->moneyIsAvailable())->toBeFalse()
            ->and($payment->received_at)->toBeNull()
            ->and($payment->confirmed_at)->not->toBeNull();

        // E a inscrição foi confirmada.
        expect($this->registration->fresh()->status)->toBe(RegistrationStatus::CONFIRMED);
    });

    it('RECEIVED libera o dinheiro', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        foreach (['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'] as $i => $eventType) {
            $this->postJson('/api/v1/webhooks/asaas', FakePaymentProvider::webhookBody(
                eventType: $eventType,
                providerPaymentId: (string) $payment->provider_payment_id,
                externalReference: $payment->external_reference,
                eventId: 'evt_'.$i,
                valueCents: 13000,
            ))->assertOk();
        }

        $payment->refresh();

        expect($payment->status)->toBe(PaymentStatus::RECEIVED)
            ->and($payment->status->moneyIsAvailable())->toBeTrue()
            ->and($payment->received_at)->not->toBeNull();
    });

    it('preenche a taxa do gateway e o líquido a partir do netValue', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        $this->postJson('/api/v1/webhooks/asaas', FakePaymentProvider::webhookBody(
            eventType: 'PAYMENT_CONFIRMED',
            providerPaymentId: (string) $payment->provider_payment_id,
            externalReference: $payment->external_reference,
            eventId: 'evt_fee',
            valueCents: 13000,
        ))->assertOk();

        $payment->refresh();
        $fee = FakePaymentProvider::simulatedFeeCents();

        expect($payment->asaas_fee_cents)->toBe($fee)
            // A identidade do §7.7, conferida também pelo CHECK do banco.
            ->and($payment->organizer_net_cents)->toBe(13000 - 650 - $fee);
    });

    /*
     * Fora de ordem é comportamento normal: o Asaas não garante ordem
     * (docs/asaas.md §4). Aplicar um CONFIRMED atrasado faria o pagamento
     * retroceder de "recebido" para "pago" e o dinheiro sair do disponível.
     */
    it('ignora CONFIRMED que chega depois de RECEIVED', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        $this->postJson('/api/v1/webhooks/asaas', FakePaymentProvider::webhookBody(
            'PAYMENT_RECEIVED', (string) $payment->provider_payment_id,
            $payment->external_reference, 'evt_recv', 13000,
        ))->assertOk();

        $this->postJson('/api/v1/webhooks/asaas', FakePaymentProvider::webhookBody(
            'PAYMENT_CONFIRMED', (string) $payment->provider_payment_id,
            $payment->external_reference, 'evt_late_confirm', 13000,
        ))->assertOk();

        // Continua RECEIVED — não retrocedeu.
        expect($payment->fresh()->status)->toBe(PaymentStatus::RECEIVED);

        // E o evento atrasado foi PROCESSADO, não marcado como falha: retentar
        // para sempre um evento superado penalizaria a fila do gateway.
        $late = PaymentWebhookEvent::query()->where('provider_event_id', 'evt_late_confirm')->sole();
        expect($late->processing_status)->toBe(WebhookProcessingStatus::PROCESSED);
    });
});

describe('webhook — idempotência e segurança', function (): void {

    /*
     * A entrega do Asaas é "at least once": o mesmo evento chega repetido. O
     * unique em (provider, provider_event_id) absorve, e a resposta é 200 —
     * responder erro penalizaria a fila do gateway.
     */
    it('reentrega do mesmo evento não duplica efeito', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        $body = FakePaymentProvider::webhookBody(
            'PAYMENT_CONFIRMED', (string) $payment->provider_payment_id,
            $payment->external_reference, 'evt_duplicado', 13000,
        );

        $this->postJson('/api/v1/webhooks/asaas', $body)
            ->assertOk()
            ->assertJsonPath('duplicate', false);

        $this->postJson('/api/v1/webhooks/asaas', $body)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        expect(PaymentWebhookEvent::query()->count())->toBe(1)
            // E o mais importante: o ledger NÃO dobrou.
            ->and(LedgerEntry::query()->where('type', LedgerEntryType::GROSS_PAYMENT->value)->count())->toBe(1);
    });

    it('persiste o evento antes de processar, mesmo sem pagamento correspondente', function (): void {
        $this->postJson('/api/v1/webhooks/asaas', FakePaymentProvider::webhookBody(
            'PAYMENT_CONFIRMED', 'pay_inexistente', 'ref_inexistente', 'evt_orfao', 13000,
        ))->assertOk();

        // Evento gravado: perder evento é perder dinheiro.
        $event = PaymentWebhookEvent::query()->where('provider_event_id', 'evt_orfao')->sole();

        expect($event->payment_id)->toBeNull()
            // FAILED de propósito: pode ser cobrança nossa que não gravou.
            ->and($event->processing_status)->toBe(WebhookProcessingStatus::FAILED);
    });

    it('marca como IGNORADO evento reconhecido sem efeito no domínio', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        $this->postJson('/api/v1/webhooks/asaas', FakePaymentProvider::webhookBody(
            'PAYMENT_CHECKOUT_VIEWED', (string) $payment->provider_payment_id,
            $payment->external_reference, 'evt_visto', 13000,
        ))->assertOk();

        $event = PaymentWebhookEvent::query()->where('provider_event_id', 'evt_visto')->sole();

        expect($event->processing_status)->toBe(WebhookProcessingStatus::IGNORED)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::PENDING);
    });

    /*
     * LGPD §12 e §9: o payload do Asaas traz bloco de cartão. Ele NÃO pode ser
     * persistido na trilha.
     */
    it('nunca persiste dado de cartão no payload gravado', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        $body = FakePaymentProvider::webhookBody(
            'PAYMENT_CONFIRMED', (string) $payment->provider_payment_id,
            $payment->external_reference, 'evt_cartao', 13000,
        );
        $body['payment']['creditCard'] = [
            'creditCardNumber' => '8829',
            'creditCardBrand' => 'MASTERCARD',
            'creditCardToken' => 'segredo-que-nao-pode-ficar',
        ];

        $this->postJson('/api/v1/webhooks/asaas', $body)->assertOk();

        $event = PaymentWebhookEvent::query()->where('provider_event_id', 'evt_cartao')->sole();

        expect(json_encode($event->payload))->not->toContain('segredo-que-nao-pode-ficar')
            ->and($event->payload['payment']['creditCard'])->toBe('[REDACTED]');
    });

    it('devolve 200 para payload não reconhecível, sem processar', function (): void {
        $this->postJson('/api/v1/webhooks/asaas', ['qualquer' => 'coisa'])
            ->assertOk()
            ->assertJsonPath('processed', false);

        expect(PaymentWebhookEvent::query()->count())->toBe(0);
    });
});

describe('ledger (CLAUDE.md §9)', function (): void {

    it('lança bruto, taxa da plataforma, taxa do gateway e líquido', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        $this->postJson('/api/v1/webhooks/asaas', FakePaymentProvider::webhookBody(
            'PAYMENT_CONFIRMED', (string) $payment->provider_payment_id,
            $payment->external_reference, 'evt_ledger', 13000,
        ))->assertOk();

        $entries = LedgerEntry::query()->where('payment_id', $payment->id)->get()
            ->keyBy(fn (LedgerEntry $e): string => $e->type->value);

        $fee = FakePaymentProvider::simulatedFeeCents();

        expect($entries)->toHaveCount(4)
            ->and($entries['GROSS_PAYMENT']->amount_cents)->toBe(13000)
            // Taxas são NEGATIVAS: saíram do bruto.
            ->and($entries['PLATFORM_FEE']->amount_cents)->toBe(-650)
            ->and($entries['ASAAS_FEE']->amount_cents)->toBe(-$fee)
            ->and($entries['ORGANIZER_NET']->amount_cents)->toBe(13000 - 650 - $fee);
    });

    /*
     * A conferência do §7.4 feita sobre a TRILHA, e não só sobre o cálculo:
     * bruto + taxas (negativas) tem de dar exatamente o líquido.
     */
    it('a soma dos lançamentos fecha a identidade do §7.4', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        $this->postJson('/api/v1/webhooks/asaas', FakePaymentProvider::webhookBody(
            'PAYMENT_CONFIRMED', (string) $payment->provider_payment_id,
            $payment->external_reference, 'evt_soma', 13000,
        ))->assertOk();

        $entries = LedgerEntry::query()->where('payment_id', $payment->id)->get();

        $gross = $entries->firstWhere('type', LedgerEntryType::GROSS_PAYMENT)?->amount_cents ?? 0;
        $fees = $entries->whereIn('type', [LedgerEntryType::PLATFORM_FEE, LedgerEntryType::ASAAS_FEE])
            ->sum('amount_cents');
        $net = $entries->firstWhere('type', LedgerEntryType::ORGANIZER_NET)?->amount_cents ?? 0;

        expect($gross + $fees)->toBe($net);
    });

    /*
     * §7.6: a taxa do Asaas é custo do organizador e NUNCA receita da
     * plataforma. Confundir os dois inflaria o faturamento do SaaS.
     */
    it('só a taxa da plataforma é receita da plataforma', function (): void {
        expect(LedgerEntryType::PLATFORM_FEE->isPlatformRevenue())->toBeTrue()
            ->and(LedgerEntryType::ASAAS_FEE->isPlatformRevenue())->toBeFalse()
            ->and(LedgerEntryType::GROSS_PAYMENT->isPlatformRevenue())->toBeFalse();
    });

    it('não lança de novo quando RECEIVED chega depois de CONFIRMED', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        foreach (['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'] as $i => $eventType) {
            $this->postJson('/api/v1/webhooks/asaas', FakePaymentProvider::webhookBody(
                $eventType, (string) $payment->provider_payment_id,
                $payment->external_reference, 'evt_dois_'.$i, 13000,
            ))->assertOk();
        }

        // Quatro lançamentos, não oito: o RECEIVED muda disponibilidade, não valores.
        expect(LedgerEntry::query()->where('payment_id', $payment->id)->count())->toBe(4);
    });

    /*
     * §9: "append-only, sem update, sem delete". Garantido por trigger no banco,
     * não por disciplina da aplicação.
     */
    it('o banco recusa update no ledger', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        $this->postJson('/api/v1/webhooks/asaas', FakePaymentProvider::webhookBody(
            'PAYMENT_CONFIRMED', (string) $payment->provider_payment_id,
            $payment->external_reference, 'evt_append', 13000,
        ))->assertOk();

        $entry = LedgerEntry::query()->where('payment_id', $payment->id)->firstOrFail();

        expect(fn (): int => DB::table('ledger_entries')->where('id', $entry->id)->update(['amount_cents' => 1]))
            ->toThrow(QueryException::class);
    });

    it('o banco recusa delete no ledger', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        $this->postJson('/api/v1/webhooks/asaas', FakePaymentProvider::webhookBody(
            'PAYMENT_CONFIRMED', (string) $payment->provider_payment_id,
            $payment->external_reference, 'evt_no_delete', 13000,
        ))->assertOk();

        $entry = LedgerEntry::query()->where('payment_id', $payment->id)->firstOrFail();

        expect(fn (): int => DB::table('ledger_entries')->where('id', $entry->id)->delete())
            ->toThrow(QueryException::class);
    });

    it('o banco recusa delete em payments', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        expect(fn (): int => DB::table('payments')->where('id', $payment->id)->delete())
            ->toThrow(QueryException::class);
    });
});

describe('GET /api/v1/payments/{id}', function (): void {

    it('o pagador consulta a própria cobrança', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        $this->actingAs($this->athlete)
            ->getJson("/api/v1/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $payment->id)
            ->assertJsonPath('data.gross_cents', 13000);
    });

    it('o organizador do evento consulta', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        $this->actingAs($this->organizerUser)
            ->getJson("/api/v1/payments/{$payment->id}")
            ->assertOk();
    });

    it('nega terceiro consultar cobrança alheia (IDOR)', function (): void {
        createCharge($this->actingAs($this->athlete))->assertCreated();
        $payment = Payment::query()->sole();

        $this->actingAs(User::factory()->player()->create())
            ->getJson("/api/v1/payments/{$payment->id}")
            ->assertForbidden();
    });

    /*
     * A cobrança vem da fábrica, não da API: `actingAs()` vale para o teste
     * inteiro, então autenticar para criar a cobrança deixaria a requisição
     * seguinte autenticada — e o teste passaria sem provar nada.
     */
    it('nega anônimo com 401', function (): void {
        $payment = Payment::factory()
            ->forRegistration($this->registration)
            ->forOrganizer($this->organizer)
            ->create();

        $this->getJson("/api/v1/payments/{$payment->id}")->assertUnauthorized();
    });
});
