<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Application;

use App\Modules\Brackets\Domain\BracketSizing;
use App\Modules\Brackets\Domain\Exceptions\DrawNotPossibleException;
use App\Modules\Brackets\Infrastructure\Models\BracketSlot;
use App\Modules\Events\Application\ChangeEventStatusAction;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Registrations\Domain\Enums\GroupStatus;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Início do sorteio (ADR 0011): cria as posições vazias da chave e move o
 * evento para `AWAITING_DRAW`.
 *
 * O tamanho da chave é decidido **agora**, a partir das duplas `COMPLETE`
 * neste instante, e fica fixo — duplas que completarem depois disso não
 * ganham posição nova (ver nota em `RandomizeDrawAction`).
 */
final readonly class StartDrawAction
{
    public function __construct(private ChangeEventStatusAction $changeStatus) {}

    public function execute(Event $event, User $actor): Event
    {
        return DB::transaction(function () use ($event, $actor): Event {
            /** @var Event $locked */
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== EventStatus::REGISTRATION_CLOSED) {
                throw DrawNotPossibleException::wrongStatus($locked->status);
            }

            $completeTeams = RegistrationGroup::query()
                ->where('event_id', $locked->id)
                ->where('status', GroupStatus::COMPLETE)
                ->count();

            if ($completeTeams < 2) {
                throw DrawNotPossibleException::notEnoughTeams($completeTeams);
            }

            $slots = BracketSizing::slotsFor($completeTeams);

            for ($position = 0; $position < $slots; $position++) {
                BracketSlot::query()->create([
                    'event_id' => $locked->id,
                    'position' => $position,
                    'registration_group_id' => null,
                    'is_bye' => false,
                ]);
            }

            return $this->changeStatus->execute($locked, $actor, EventStatus::AWAITING_DRAW);
        });
    }
}
