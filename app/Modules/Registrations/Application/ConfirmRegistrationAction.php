<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Registrations\Domain\Enums\GroupStatus;
use App\Modules\Registrations\Domain\Enums\RegistrationStatus;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Confirma a inscrição quando o pagamento é confirmado (ADR 0003).
 *
 * Chamada pelo módulo Payments através da camada `Application` — nunca por
 * `Model::where()` cruzando fronteira (CLAUDE.md §4.1).
 *
 * ## Idempotente por construção
 *
 * O webhook do Asaas é "at least once", e `CONFIRMED` e `RECEIVED` chegam como
 * dois eventos que ambos confirmam a inscrição. Chamar duas vezes tem de ser
 * inofensivo: se a inscrição já está confirmada, a action não faz nada e não
 * lança. Lançar faria o job marcar como falha um evento perfeitamente normal.
 *
 * ## Não lança em transição inválida
 *
 * Inscrição cancelada que recebe pagamento confirmado é um caso real: o atleta
 * cancelou e o PIX caiu depois. Confirmar seria errado (a vaga foi liberada), e
 * lançar penalizaria a fila do webhook. A action registra na auditoria que houve
 * pagamento sobre inscrição cancelada — que é exatamente o caso que precisa de
 * olho humano para reembolso.
 */
final readonly class ConfirmRegistrationAction
{
    public function __construct(
        private EventOccupancy $occupancy,
        private AuditLogger $audit,
    ) {}

    public function execute(Payment $payment, CarbonImmutable $now): bool
    {
        $confirmed = DB::transaction(function () use ($payment, $now): ?bool {
            /*
             * Ordem de lock: evento → inscrição. A mesma de
             * `CreateRegistrationAction` e `CancelRegistrationAction`. Ordem
             * consistente é o que evita deadlock entre elas.
             */
            $event = $this->occupancy->lock($payment->event_id);

            /** @var Registration $registration */
            $registration = Registration::query()
                ->whereKey($payment->registration_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($registration->status === RegistrationStatus::CONFIRMED) {
                return null;   // já confirmada: reentrega de webhook
            }

            if (! $registration->status->canTransitionTo(RegistrationStatus::CONFIRMED)) {
                return false;  // cancelada/expirada — pagamento sobre inscrição morta
            }

            $registration->status = RegistrationStatus::CONFIRMED;
            $registration->confirmed_at = $now;
            // A reserva cumpriu o papel: a vaga agora é definitiva.
            $registration->reserved_until = null;
            $registration->save();

            $this->completeGroupIfReady($registration);
            $this->occupancy->syncCounter($event, $now);

            return true;
        });

        if ($confirmed === null) {
            return false;
        }

        if ($confirmed === false) {
            /*
             * Dinheiro entrou para inscrição que não existe mais. Não é erro de
             * sistema, é situação de negócio que exige decisão humana sobre
             * reembolso — então vira trilha, não exceção.
             */
            $this->audit->log(
                action: AuditAction::ADMIN_ACTION,
                actor: null,
                targetType: 'payment',
                targetId: $payment->id,
                metadata: [
                    'operation' => 'PAYMENT_ON_INACTIVE_REGISTRATION',
                    'registration_id' => $payment->registration_id,
                    'gross_cents' => $payment->gross_cents,
                    'requires_human_review' => true,
                ],
            );

            return false;
        }

        $this->audit->log(
            action: AuditAction::REGISTRATION_CONFIRMED,
            actor: null,
            targetType: 'registration',
            targetId: $payment->registration_id,
            metadata: [
                'payment_id' => $payment->id,
                'event_id' => $payment->event_id,
                'confirmed_by' => 'gateway_webhook',
            ],
        );

        return true;
    }

    /**
     * ADR 0001: "a dupla só é considerada completa quando as DUAS registrations
     * estiverem confirmadas".
     */
    private function completeGroupIfReady(Registration $registration): void
    {
        if ($registration->group_id === null) {
            return;
        }

        $group = RegistrationGroup::query()->lockForUpdate()->find($registration->group_id);

        if (! $group instanceof RegistrationGroup) {
            return;
        }

        $pending = Registration::query()
            ->where('group_id', $group->id)
            ->where('status', '!=', RegistrationStatus::CONFIRMED->value)
            ->exists();

        if (! $pending) {
            $group->status = GroupStatus::COMPLETE;
            $group->save();
        }
    }
}
