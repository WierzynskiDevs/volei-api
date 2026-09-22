<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Application;

use App\Modules\Brackets\Domain\Exceptions\DrawNotEditableException;
use App\Modules\Brackets\Infrastructure\Models\BracketSlot;
use App\Modules\Events\Application\ChangeEventStatusAction;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Publicação da chave (ADR 0011): fecha o sorteio, vira imutável.
 *
 * Qualquer posição ainda vazia neste instante vira "bye" — dupla a menos que
 * o tamanho da chave calculado em `StartDrawAction`. Depois de publicada, as
 * demais actions do módulo recusam mudança porque checam
 * `event->status === AWAITING_DRAW`.
 */
final readonly class PublishBracketAction
{
    public function __construct(private ChangeEventStatusAction $changeStatus) {}

    public function execute(Event $event, User $actor): Event
    {
        return DB::transaction(function () use ($event, $actor): Event {
            /** @var Event $locked */
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== EventStatus::AWAITING_DRAW) {
                throw DrawNotEditableException::forStatus($locked->status);
            }

            BracketSlot::query()
                ->where('event_id', $locked->id)
                ->whereNull('registration_group_id')
                ->update(['is_bye' => true]);

            return $this->changeStatus->execute($locked, $actor, EventStatus::BRACKET_PUBLISHED);
        });
    }
}
