<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Payments\Domain\DTO\ChargeRequest;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Exceptions\PaymentNotAllowedException;
use App\Modules\Payments\Domain\PaymentBreakdown;
use App\Modules\Payments\Domain\PaymentProviderInterface;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Registrations\Domain\Enums\RegistrationStatus;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Users\Infrastructure\Models\User;
use App\Shared\Domain\FeeRate;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Criação da cobrança de uma inscrição (BRIEF §31, ADR 0009).
 *
 * Consumidor: `/checkout/{slug}` no volei-app.
 *
 * ## A ordem é a parte importante (ADR 0009 §8)
 *
 * 1. **transação curta**: valida, calcula a decomposição e grava `payments` como
 *    `DRAFT`, já com a taxa congelada e a `Idempotency-Key`;
 * 2. **fora da transação**: chama o gateway. `CLAUDE.md` §8 proíbe chamada HTTP
 *    dentro de transação de banco — uma cobrança lenta no Asaas seguraria lock
 *    e derrubaria a criação de inscrições em paralelo;
 * 3. **transação curta**: grava `provider_payment_id` e o que o gateway devolveu.
 *
 * Falha no passo 2 deixa o pagamento em `DRAFT` sem `provider_payment_id`. Isso
 * é estado explícito e reprocessável pela reconciliação — nunca um sucesso
 * falso (§25).
 *
 * ## Idempotência (CLAUDE.md §8)
 *
 * `Idempotency-Key` repetida devolve **o mesmo pagamento**, sem criar nova
 * cobrança no gateway. Sem isso, duplo clique no checkout geraria duas cobranças
 * para a mesma inscrição — e o atleta pagaria duas vezes.
 */
final readonly class CreatePaymentAction
{
    public function __construct(
        private PaymentProviderInterface $provider,
        private AuditLogger $audit,
    ) {}

    public function execute(
        Registration $registration,
        User $actor,
        PaymentMethod $method,
        string $idempotencyKey,
        CarbonImmutable $now,
    ): Payment {
        /*
         * Chave repetida devolve a mesma resposta, sem novo efeito. Checado
         * antes de tudo: é o caminho mais provável do duplo clique.
         */
        $existing = $this->findByIdempotencyKey($actor->id, $idempotencyKey);

        if ($existing instanceof Payment) {
            return $existing;
        }

        /*
         * O evento é resolvido UMA vez, com guarda, e passado adiante. A relação
         * é anulável no tipo (FK existe, mas o Eloquent não garante que veio
         * carregada), e espalhar `?->` pelo código esconderia o caso em que ela
         * realmente falta.
         */
        $event = $this->resolveEvent($registration);

        $this->assertPayable($registration, $event);

        $payment = DB::transaction(
            fn (): Payment => $this->persistDraft($registration, $event, $actor, $method, $idempotencyKey, $now),
        );

        // --- Fora da transação: HTTP externo (CLAUDE.md §8) ---
        $snapshot = $this->provider->createCharge(new ChargeRequest(
            gross: $payment->gross(),
            method: $method,
            dueDate: $payment->due_at,
            externalReference: $payment->external_reference,
            description: "Inscrição — {$event->name}",
            payerName: $actor->name,
            payerEmail: $actor->email,
            platformFee: $payment->platformFee(),
        ));

        $payment = DB::transaction(function () use ($payment, $snapshot, $now): Payment {
            /** @var Payment $fresh */
            $fresh = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            $fresh->provider_payment_id = $snapshot->providerPaymentId;
            $fresh->checkout_url = $snapshot->checkoutUrl;
            $fresh->pix_payload = $snapshot->pixPayload;
            $fresh->reconciled_at = $now;

            /*
             * O gateway pode já devolver estado adiantado (cartão aprovado na
             * hora). Respeita a máquina de estados: só avança se a transição for
             * válida, senão fica em PENDING e o webhook resolve.
             */
            $target = $snapshot->status === PaymentStatus::DRAFT
                ? PaymentStatus::PENDING
                : $snapshot->status;

            $fresh->status = $fresh->status->canTransitionTo($target)
                ? $target
                : PaymentStatus::PENDING;

            if ($fresh->status->confirmsRegistration() && $fresh->confirmed_at === null) {
                $fresh->confirmed_at = $snapshot->confirmedAt ?? $now;
            }

            $fresh->save();

            return $fresh;
        });

        $this->audit->log(
            action: AuditAction::PAYMENT_CREATED,
            actor: $actor,
            targetType: 'payment',
            targetId: $payment->id,
            metadata: [
                'registration_id' => $registration->id,
                'event_id' => $payment->event_id,
                'method' => $method->value,
                'gross_cents' => $payment->gross_cents,
                // Taxa congelada, registrada na trilha (§7.5).
                'platform_fee_cents' => $payment->platform_fee_cents,
                'platform_fee_basis_points' => $payment->platform_fee_basis_points,
                'provider' => $payment->provider,
                'provider_payment_id' => $payment->provider_payment_id,
                'status' => $payment->status->value,
            ],
        );

        return $payment;
    }

    private function findByIdempotencyKey(string $userId, string $key): ?Payment
    {
        return Payment::query()
            ->where('user_id', $userId)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function resolveEvent(Registration $registration): Event
    {
        $registration->loadMissing('event.organizer.plan');
        $event = $registration->event;

        if (! $event instanceof Event) {
            throw PaymentNotAllowedException::eventMissing();
        }

        return $event;
    }

    /**
     * A inscrição admite cobrança?
     *
     * Regra e não formalidade: cobrar inscrição cancelada tiraria dinheiro de
     * quem não tem vaga, e cobrar de novo quem já pagou é cobrança duplicada.
     */
    private function assertPayable(Registration $registration, Event $event): void
    {
        if ($registration->status === RegistrationStatus::CONFIRMED) {
            throw PaymentNotAllowedException::alreadyConfirmed();
        }

        if ($registration->status->isTerminal()) {
            throw PaymentNotAllowedException::registrationTerminal($registration->status);
        }

        /*
         * Evento gratuito confirma na própria inscrição (ADR 0001) e não gera
         * cobrança. Cobrar aqui criaria cobrança de R$ 0 no gateway.
         */
        if ($event->isFree()) {
            throw PaymentNotAllowedException::freeEvent();
        }
    }

    private function persistDraft(
        Registration $registration,
        Event $event,
        User $actor,
        PaymentMethod $method,
        string $idempotencyKey,
        CarbonImmutable $now,
    ): Payment {
        $organizer = $event->organizer;

        if ($organizer === null) {
            throw PaymentNotAllowedException::eventMissing();
        }

        $plan = $organizer->plan;

        /*
         * A taxa vem do PLANO, lida do banco, nunca hardcoded (§7.8). Plano
         * ausente = taxa zero: é a leitura conservadora — cobrar taxa que não
         * está configurada seria pior do que não cobrar.
         */
        $feeRate = $plan?->feeRate() ?? FeeRate::zero();
        $feeFixed = $plan?->fixedFee() ?? Money::zero();

        /*
         * A decomposição é calculada AQUI e congelada. Se as taxas não couberem
         * no bruto, `PaymentBreakdown` lança — e a cobrança não chega a existir
         * nem localmente nem no gateway (ADR 0009 §1).
         */
        $breakdown = PaymentBreakdown::forCharge(
            gross: $event->registrationFee(),
            platformFeeRate: $feeRate,
            platformFeeFixed: $feeFixed,
        );

        $payment = new Payment;

        $payment->fill([
            'registration_id' => $registration->id,
            'event_id' => $event->id,
            'organizer_id' => $organizer->id,
            'user_id' => $actor->id,
            'method' => $method->value,
            'provider' => $this->provider->name(),
            // `external_reference` é o próprio id, gerado antes do save para
            // poder ir na chamada ao gateway.
            'external_reference' => $payment->newUniqueId(),
            'idempotency_key' => $idempotencyKey,
            'due_at' => $this->dueDateFor($method, $now),
        ]);

        $payment->status = PaymentStatus::DRAFT;
        $payment->gross_cents = $breakdown->gross->cents;
        $payment->platform_fee_basis_points = $breakdown->platformFeeRate->basisPoints;
        $payment->platform_fee_fixed_cents = $breakdown->platformFeeFixed->cents;
        $payment->platform_fee_cents = $breakdown->platformFee->cents;
        // Taxa do gateway e líquido nascem NULOS: só o gateway sabe (§5 do ADR).
        $payment->asaas_fee_cents = null;
        $payment->organizer_net_cents = null;

        /*
         * O insert vai dentro de uma transação ANINHADA, que no Laravel vira
         * `SAVEPOINT`. Isso é obrigatório no PostgreSQL: um statement que falha
         * aborta a transação inteira (`SQLSTATE 25P02`), e a consulta seguinte —
         * que busca a cobrança já existente — falharia com "current transaction
         * is aborted". Com savepoint, só o insert é desfeito.
         */
        try {
            DB::transaction(fn (): bool => $payment->save());
        } catch (UniqueConstraintViolationException) {
            /*
             * Duas requisições simultâneas com a mesma chave, ou uma cobrança
             * viva já existente para a inscrição. Devolve a que existe — é o
             * comportamento de idempotência, não erro.
             */
            $existing = $this->findByIdempotencyKey($actor->id, $idempotencyKey);

            if ($existing instanceof Payment) {
                return $existing;
            }

            throw PaymentNotAllowedException::alreadyHasOpenCharge();
        }

        return $payment;
    }

    /**
     * Vencimento da cobrança.
     *
     * Alinhado com a reserva de vaga (ADR 0003): a vaga fica reservada pelo
     * mesmo período em que a cobrança pode ser paga. Desalinhar os dois criaria
     * cobrança viva para vaga já liberada.
     */
    private function dueDateFor(PaymentMethod $method, CarbonImmutable $now): CarbonImmutable
    {
        if (! $method->hasDueDate()) {
            return $now;
        }

        return $now->addMinutes((int) config('saque.payments.reservation_ttl_minutes'));
    }
}
