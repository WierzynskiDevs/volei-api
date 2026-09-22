<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Organizers\Domain\Enums\OrganizerStatus;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;

/**
 * Situação do organizador: regular, em atenção ou bloqueado (ADR 0010 §2).
 *
 * Consumidores: os botões "Advertir" e "Suspender" de `/admin/organizadores`.
 *
 * `organizers.status` está fora do `$fillable` — esta é a única porta de escrita.
 * Bloquear aqui tem efeito imediato em duas regras que já existiam e que
 * consultam o status na hora de operar:
 *
 *  - `CreateEventAction` recusa criar evento de organizador que não pode operar;
 *  - `Organizer::canReceivePayments()` recusa evento pago sem destino de repasse.
 *
 * O que esta action **não** faz: mexer no `users.status` do dono. São coisas
 * diferentes — suspender a operação de campeonatos de alguém não suspende a
 * conta pessoal dela, que pode seguir jogando como atleta. Quem quiser as duas
 * coisas executa as duas ações, e as duas ficam na trilha.
 */
final readonly class ChangeOrganizerStatusAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(
        Organizer $organizer,
        User $actor,
        OrganizerStatus $status,
        string $reason,
    ): Organizer {
        $from = $organizer->status;

        if ($from === $status) {
            return $organizer;
        }

        $organizer->status = $status;
        $organizer->save();

        $this->audit->log(
            /*
             * Bloqueio tem caso próprio porque é o registro que interessa numa
             * investigação; liberar e advertir compartilham o caso genérico.
             */
            action: $status === OrganizerStatus::BLOCKED
                ? AuditAction::ORGANIZER_BLOCKED
                : AuditAction::ORGANIZER_STATUS_CHANGED,
            actor: $actor,
            targetType: 'organizer',
            targetId: $organizer->id,
            metadata: [
                'from' => $from->value,
                'to' => $status->value,
                'reason' => $reason,
            ],
        );

        return $organizer;
    }
}
