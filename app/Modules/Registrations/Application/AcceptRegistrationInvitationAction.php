<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Registrations\Domain\Enums\RegistrationStatus;
use App\Modules\Registrations\Domain\Exceptions\InvalidRegistrationTransitionException;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * O parceiro convidado aceita entrar na dupla (Q14, ADR 0015).
 *
 * Consumidor: `/minhas-inscricoes`, onde o parceiro vê a própria inscrição em
 * `PENDING_ACCEPTANCE` (criada por quem o convidou, `CreateRegistrationAction`)
 * e toca em aceitar. Sai de `PENDING_ACCEPTANCE` para `CONFIRMED` (evento
 * gratuito) ou `PENDING_PAYMENT` (evento pago — o parceiro paga a própria parte
 * em seguida, ADR 0001: cobrança é por jogador).
 *
 * ## Por que não precisa checar vaga de novo
 *
 * `EventOccupancy::occupiedSlots()` conta **duplas distintas** com ao menos uma
 * inscrição ocupando (`distinct()->count('group_id')`). O capitão já ocupa a
 * vaga da dupla desde que se inscreveu — o parceiro aceitando não soma uma
 * segunda vaga para o mesmo grupo. `syncCounter()` roda mesmo assim, pelo
 * mesmo motivo de sempre: recomputar é a fonte de verdade, nunca incrementar.
 */
final readonly class AcceptRegistrationInvitationAction
{
    public function __construct(
        private EventOccupancy $occupancy,
        private AuditLogger $audit,
    ) {}

    public function execute(Registration $registration, User $actor, CarbonImmutable $now): Registration
    {
        $accepted = DB::transaction(function () use ($registration, $now): Registration {
            $event = $this->occupancy->lock($registration->event_id);

            /** @var Registration $locked */
            $locked = Registration::query()
                ->whereKey($registration->id)
                ->lockForUpdate()
                ->firstOrFail();

            $target = $event->isFree()
                ? RegistrationStatus::CONFIRMED
                : RegistrationStatus::PENDING_PAYMENT;

            if (! $locked->status->canTransitionTo($target)) {
                throw InvalidRegistrationTransitionException::from($locked->status, $target);
            }

            $locked->status = $target;

            if ($target === RegistrationStatus::CONFIRMED) {
                $locked->confirmed_at = $now;
            } else {
                $locked->reserved_until = $now->addMinutes(
                    (int) config('saque.payments.reservation_ttl_minutes'),
                );
            }

            $locked->save();

            $this->occupancy->syncCounter($event, $now);

            return $locked;
        });

        $this->audit->log(
            action: AuditAction::REGISTRATION_ACCEPTED,
            actor: $actor,
            targetType: 'registration',
            targetId: $accepted->id,
            metadata: [
                'event_id' => $accepted->event_id,
                'group_id' => $accepted->group_id,
                'status' => $accepted->status->value,
            ],
        );

        return $accepted;
    }
}
