<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Domain\Exceptions\InvalidEventTransitionException;
use App\Modules\Events\Domain\Exceptions\PaidEventRequiresPaymentAccountException;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ponto único de transição de estado do evento (CLAUDE.md §8).
 *
 * Toda mudança de status passa por aqui — nenhum controller escreve
 * `$event->status = ...`. Isso é o que garante que a máquina de estados seja
 * de verdade uma máquina de estados: transição inválida lança exceção, e cada
 * transição deixa rastro na auditoria.
 */
final readonly class ChangeEventStatusAction
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  string|null  $reason  obrigatório no cancelamento (BRIEF §35/§36)
     */
    public function execute(
        Event $event,
        User $actor,
        EventStatus $target,
        ?string $reason = null,
        ?CarbonImmutable $now = null,
    ): Event {
        $current = $event->status;

        if (! $current->canTransitionTo($target)) {
            throw InvalidEventTransitionException::from($current, $target);
        }

        if ($target === EventStatus::CANCELLED && ($reason === null || trim($reason) === '')) {
            // O check constraint do banco também rejeita, mas a mensagem daqui
            // é a que o organizador consegue entender.
            throw new InvalidArgumentException('O cancelamento exige justificativa.');
        }

        if ($target === EventStatus::PUBLISHED) {
            $this->assertCanBePublished($event);
        }

        // Tempo injetado: fixture determinística, sem `now()` implícito
        // (CLAUDE.md §22).
        $moment = $now ?? CarbonImmutable::now();

        DB::transaction(function () use ($event, $target, $reason, $moment): void {
            $event->status = $target;

            if ($target === EventStatus::PUBLISHED && $event->published_at === null) {
                $event->published_at = $moment;
            }

            if ($target === EventStatus::CANCELLED) {
                $event->cancelled_at = $moment;
                $event->cancellation_reason = $reason;
            }

            $event->save();
        });

        $this->audit->log(
            action: $this->auditActionFor($target),
            actor: $actor,
            targetType: 'event',
            targetId: $event->id,
            metadata: array_filter([
                'from' => $current->value,
                'to' => $target->value,
                'reason' => $reason,
            ], fn (mixed $v): bool => $v !== null),
        );

        return $event;
    }

    /**
     * Não se publica evento pago sem destino para o dinheiro
     * (docs/OPEN-QUESTIONS.md Q6). Evento gratuito não tem essa restrição.
     */
    private function assertCanBePublished(Event $event): void
    {
        if ($event->isFree()) {
            return;
        }

        $organizer = $event->organizer;

        if (! $organizer instanceof Organizer || ! $organizer->canReceivePayments()) {
            throw PaidEventRequiresPaymentAccountException::create();
        }
    }

    private function auditActionFor(EventStatus $target): AuditAction
    {
        return match ($target) {
            EventStatus::PUBLISHED => AuditAction::EVENT_PUBLISHED,
            EventStatus::REGISTRATION_OPEN => AuditAction::EVENT_REGISTRATIONS_OPENED,
            EventStatus::REGISTRATION_CLOSED => AuditAction::EVENT_REGISTRATIONS_CLOSED,
            EventStatus::CANCELLED => AuditAction::EVENT_CANCELLED,
            EventStatus::AWAITING_DRAW => AuditAction::EVENT_DRAW_STARTED,
            EventStatus::BRACKET_PUBLISHED => AuditAction::EVENT_BRACKET_PUBLISHED,
            EventStatus::IN_PROGRESS => AuditAction::EVENT_STARTED,
            default => AuditAction::EVENT_UPDATED,
        };
    }
}
