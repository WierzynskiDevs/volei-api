<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;

/**
 * Publicação do evento — o botão "Publicar e gerar link" do baseline.
 *
 * Publicar faz duas coisas distintas, e é importante que continuem distintas
 * no modelo: torna o evento visível publicamente (PUBLISHED) e, quando a janela
 * de inscrição já está valendo, abre as inscrições (REGISTRATION_OPEN).
 *
 * Cada passo é uma transição de verdade, com auditoria própria — o que a action
 * faz é encadeá-los, não pular a máquina de estados.
 */
final readonly class PublishEventAction
{
    public function __construct(private ChangeEventStatusAction $changeStatus) {}

    public function execute(Event $event, User $actor, ?CarbonImmutable $now = null): Event
    {
        $moment = $now ?? CarbonImmutable::now();

        $event = $this->changeStatus->execute(
            event: $event,
            actor: $actor,
            target: EventStatus::PUBLISHED,
            now: $moment,
        );

        if ($this->registrationWindowIsOpen($event, $moment)) {
            $event = $this->changeStatus->execute(
                event: $event,
                actor: $actor,
                target: EventStatus::REGISTRATION_OPEN,
                now: $moment,
            );
        }

        return $event;
    }

    /**
     * Sem janela declarada, publicar já abre inscrição — é o comportamento que
     * o baseline mostra (o evento publicado aparece como "Inscrições abertas").
     *
     * Com janela declarada, a data manda: um evento cuja abertura é semana que
     * vem fica PUBLICADO e visível, mas ainda sem receber inscrição.
     */
    private function registrationWindowIsOpen(Event $event, CarbonImmutable $now): bool
    {
        if ($event->registration_open_at !== null && $now->lessThan($event->registration_open_at)) {
            return false;
        }

        if ($event->registration_close_at !== null && $now->greaterThan($event->registration_close_at)) {
            return false;
        }

        return true;
    }
}
