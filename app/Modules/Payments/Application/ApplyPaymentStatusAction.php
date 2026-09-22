<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Finance\Application\LedgerWriter;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Registrations\Application\ConfirmRegistrationAction;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Aplica um estado vindo do gateway a um pagamento.
 *
 * Ponto único de mudança de estado financeiro. Duas fontes chamam esta action, e
 * são exatamente as duas que o `CLAUDE.md` §14 aceita:
 *
 *  - o **webhook verificado** (`ProcessWebhookEventJob`);
 *  - a **reconciliação** por consulta ao gateway.
 *
 * O retorno do checkout não chama. Nunca.
 *
 * ## Fora de ordem não é erro
 *
 * O Asaas não garante ordem de entrega (docs/asaas.md §4): um
 * `PAYMENT_CONFIRMED` atrasado pode chegar depois de um `PAYMENT_RECEIVED`.
 * Aplicá-lo faria o pagamento retroceder de "recebido" para "pago" e o dinheiro
 * sair do disponível.
 *
 * Por isso a transição inválida aqui **não lança**: devolve
 * `AppliedStatus::OUT_OF_ORDER`, que o job registra como processado. O evento
 * não é perdido nem reprocessado em loop — é reconhecido como já superado.
 */
final readonly class ApplyPaymentStatusAction
{
    public function __construct(
        private LedgerWriter $ledger,
        private ConfirmRegistrationAction $confirmRegistration,
        private AuditLogger $audit,
    ) {}

    public function execute(
        Payment $payment,
        PaymentStatus $target,
        CarbonImmutable $now,
        ?Money $gatewayFee = null,
        ?Money $refundedTotal = null,
    ): AppliedStatus {
        $result = DB::transaction(function () use ($payment, $target, $now, $gatewayFee, $refundedTotal): array {
            /** @var Payment $fresh */
            $fresh = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            /*
             * Mesmo estado chegando de novo é o caso comum na reentrega do
             * webhook. Ainda assim vale preencher a taxa do gateway, que pode
             * ter vindo só no segundo evento.
             */
            if ($fresh->status === $target) {
                $enriched = $this->fillGatewayFee($fresh, $gatewayFee);

                return [$enriched ? AppliedStatus::ENRICHED : AppliedStatus::UNCHANGED, $fresh];
            }

            if (! $fresh->status->canTransitionTo($target)) {
                return [AppliedStatus::OUT_OF_ORDER, $fresh];
            }

            $this->fillGatewayFee($fresh, $gatewayFee);

            $fresh->status = $target;
            $this->stampTimestamps($fresh, $target, $now);

            if ($refundedTotal !== null) {
                $fresh->refunded_cents = min($refundedTotal->cents, $fresh->gross_cents);
            }

            $fresh->reconciled_at = $now;
            $fresh->save();

            /*
             * Ledger na CONFIRMAÇÃO, não no recebimento: é aí que o dinheiro
             * passa a existir como fato (ADR 0009 §2). O `RECEIVED` posterior
             * muda disponibilidade, não valores — e por isso não lança de novo.
             */
            if ($target === PaymentStatus::CONFIRMED || $target === PaymentStatus::RECEIVED) {
                $this->ledger->recordConfirmedPayment($fresh, $now);
            }

            if ($target === PaymentStatus::REFUNDED || $target === PaymentStatus::PARTIALLY_REFUNDED) {
                $this->ledger->recordRefund(
                    $fresh,
                    Money::fromCents($fresh->refunded_cents > 0 ? $fresh->refunded_cents : $fresh->gross_cents),
                    $now,
                    'Estorno informado pelo gateway',
                );
            }

            if ($target === PaymentStatus::CHARGEBACK) {
                $this->ledger->recordChargeback($fresh, $fresh->gross(), $now);
            }

            return [AppliedStatus::APPLIED, $fresh];
        });

        [$outcome, $fresh] = $result;

        if ($outcome !== AppliedStatus::APPLIED) {
            return $outcome;
        }

        /*
         * Confirmar a inscrição acontece FORA da transação do pagamento, com
         * transação própria: são dois agregados e o lock do evento (para a
         * contagem de vagas) precisa ser adquirido na mesma ordem que
         * `CreateRegistrationAction` usa, senão há risco de deadlock.
         */
        if ($target->confirmsRegistration()) {
            $this->confirmRegistration->execute($fresh, $now);
        }

        $this->audit->log(
            action: $this->auditActionFor($target),
            actor: null,   // origem é o gateway, não uma pessoa
            targetType: 'payment',
            targetId: $fresh->id,
            metadata: [
                'status' => $target->value,
                'registration_id' => $fresh->registration_id,
                'event_id' => $fresh->event_id,
                'organizer_id' => $fresh->organizer_id,
                'gross_cents' => $fresh->gross_cents,
                'platform_fee_cents' => $fresh->platform_fee_cents,
                'asaas_fee_cents' => $fresh->asaas_fee_cents,
                'organizer_net_cents' => $fresh->organizer_net_cents,
                'provider' => $fresh->provider,
                'provider_payment_id' => $fresh->provider_payment_id,
            ],
        );

        return $outcome;
    }

    /**
     * Preenche a taxa do gateway e recalcula o líquido.
     *
     * Só uma vez: a taxa é fato do gateway e não muda. Sobrescrever abriria a
     * porta para um evento posterior alterar o líquido de um pagamento já
     * conferido (§7.5).
     *
     * @return bool se algo foi preenchido
     */
    private function fillGatewayFee(Payment $payment, ?Money $gatewayFee): bool
    {
        if ($gatewayFee === null || $payment->asaas_fee_cents !== null) {
            return false;
        }

        $breakdown = $payment->breakdown()->withAsaasFee($gatewayFee);
        $net = $breakdown->organizerNet();

        $payment->asaas_fee_cents = $gatewayFee->cents;
        $payment->organizer_net_cents = $net?->cents;
        $payment->save();

        return true;
    }

    private function stampTimestamps(Payment $payment, PaymentStatus $target, CarbonImmutable $now): void
    {
        // `confirmed_at` nunca é sobrescrito: é o instante em que o atleta pagou.
        if ($target->confirmsRegistration() && $payment->confirmed_at === null) {
            $payment->confirmed_at = $now;
        }

        if ($target === PaymentStatus::RECEIVED && $payment->received_at === null) {
            $payment->received_at = $now;
        }

        if ($target === PaymentStatus::REFUNDED && $payment->refunded_at === null) {
            $payment->refunded_at = $now;
        }

        if ($target === PaymentStatus::FAILED && $payment->failed_at === null) {
            $payment->failed_at = $now;
        }
    }

    /**
     * `CLAUDE.md` §9 já prevê `PAYMENT_CONFIRMED` e `PAYMENT_RECEIVED` como
     * ações distintas — é a mesma distinção do ADR 0009 §2 na trilha.
     */
    private function auditActionFor(PaymentStatus $status): AuditAction
    {
        return match ($status) {
            PaymentStatus::CONFIRMED => AuditAction::PAYMENT_CONFIRMED,
            PaymentStatus::RECEIVED => AuditAction::PAYMENT_RECEIVED,
            PaymentStatus::REFUNDED,
            PaymentStatus::PARTIALLY_REFUNDED => AuditAction::REFUND_COMPLETED,
            PaymentStatus::FAILED => AuditAction::PAYMENT_FAILED,
            default => AuditAction::PAYMENT_CREATED,
        };
    }
}
