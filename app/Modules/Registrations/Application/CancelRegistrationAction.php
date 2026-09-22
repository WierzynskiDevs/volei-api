<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Registrations\Domain\Enums\GroupStatus;
use App\Modules\Registrations\Domain\Enums\RegistrationStatus;
use App\Modules\Registrations\Domain\Exceptions\InvalidRegistrationTransitionException;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cancelamento de inscrição.
 *
 * Consumidores: `/minhas-inscricoes` (o atleta desiste) e o fluxo do aditivo §18
 * ("solicitar cancelamento" depois de reprovado).
 *
 * Sobre dinheiro: esta action **não** processa reembolso. Se havia pagamento
 * confirmado, o direito ao reembolso segue a regra do CLAUDE.md §14 —
 * desistência voluntária em evento mantido como publicado não gera reembolso
 * automático; vale a política do evento. Quem cria o reembolso é o módulo
 * Payments, e o cancelamento aqui apenas registra o fato e libera a vaga.
 *
 * A dupla não é apagada: o grupo vai para `INCOMPLETE` e o parceiro continua
 * existindo, porque o aditivo §18 dá ao capitão a saída de trocar de dupla.
 */
final readonly class CancelRegistrationAction
{
    public function __construct(
        private EventOccupancy $occupancy,
        private AuditLogger $audit,
    ) {}

    public function execute(
        Registration $registration,
        User $actor,
        CarbonImmutable $now,
        ?string $reason = null,
    ): Registration {
        $registration = DB::transaction(function () use ($registration, $now, $reason): Registration {
            /*
             * Lock do evento primeiro, sempre nesta ordem (evento → inscrição).
             * Ordem consistente de aquisição de lock é o que evita deadlock
             * entre esta action e `CreateRegistrationAction`, que trava na mesma
             * sequência.
             */
            $event = $this->occupancy->lock($registration->event_id);

            /** @var Registration $fresh */
            $fresh = Registration::query()
                ->whereKey($registration->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $fresh->status->canTransitionTo(RegistrationStatus::CANCELLED)) {
                throw InvalidRegistrationTransitionException::from($fresh->status, RegistrationStatus::CANCELLED);
            }

            $fresh->status = RegistrationStatus::CANCELLED;
            $fresh->cancelled_at = $now;
            $fresh->cancellation_reason = $reason;
            // A reserva perde sentido: mantê-la faria a vaga parecer ocupada
            // para a contagem até vencer sozinha.
            $fresh->reserved_until = null;
            $fresh->save();

            $this->markGroupIncomplete($fresh);
            $this->occupancy->syncCounter($event, $now);

            return $fresh;
        });

        $this->audit->log(
            action: AuditAction::REGISTRATION_CANCELLED,
            actor: $actor,
            targetType: 'registration',
            targetId: $registration->id,
            metadata: [
                'event_id' => $registration->event_id,
                'cancelled_by_self' => $actor->id === $registration->user_id,
                'reason' => $reason,
                'level_review' => $registration->level_review->value,
            ],
        );

        return $registration;
    }

    /**
     * A dupla perdeu um membro ativo.
     *
     * `INCOMPLETE` em vez de `CANCELLED` porque o outro atleta pode ter pagado e
     * continua inscrito — cancelar o grupo inteiro apagaria a participação de
     * quem não desistiu.
     */
    private function markGroupIncomplete(Registration $registration): void
    {
        if ($registration->group_id === null) {
            return;
        }

        $group = RegistrationGroup::query()->lockForUpdate()->find($registration->group_id);

        if (! $group instanceof RegistrationGroup) {
            return;
        }

        $stillActive = Registration::query()
            ->where('group_id', $group->id)
            ->whereIn('status', RegistrationStatus::blockingValues())
            ->exists();

        $group->status = $stillActive ? GroupStatus::INCOMPLETE : GroupStatus::CANCELLED;
        $group->save();
    }
}
