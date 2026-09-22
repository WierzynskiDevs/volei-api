<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Application;

use App\Modules\Brackets\Domain\Exceptions\DrawNotEditableException;
use App\Modules\Brackets\Domain\Exceptions\RegistrationGroupNotEligibleException;
use App\Modules\Brackets\Infrastructure\Models\BracketSlot;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Registrations\Domain\Enums\GroupStatus;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use Illuminate\Support\Facades\DB;

/**
 * Encaixe manual de uma dupla numa posição da chave (ADR 0011) — "eu posso
 * selecionar a dupla e encaixar ela".
 *
 * Uma dupla nunca fica duplicada: se ela já ocupava outra posição, as duas
 * posições trocam de conteúdo (swap), em vez de a dupla aparecer duas vezes.
 * `$registrationGroupId === null` limpa a posição.
 */
final readonly class PlaceGroupInSlotAction
{
    public function execute(Event $event, int $position, ?string $registrationGroupId): void
    {
        DB::transaction(function () use ($event, $position, $registrationGroupId): void {
            /** @var Event $locked */
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== EventStatus::AWAITING_DRAW) {
                throw DrawNotEditableException::forStatus($locked->status);
            }

            $targetSlot = BracketSlot::query()
                ->where('event_id', $locked->id)
                ->where('position', $position)
                ->firstOrFail();

            if ($registrationGroupId === null) {
                $targetSlot->registration_group_id = null;
                $targetSlot->is_bye = false;
                $targetSlot->save();

                return;
            }

            $group = RegistrationGroup::query()
                ->where('id', $registrationGroupId)
                ->where('event_id', $locked->id)
                ->where('status', GroupStatus::COMPLETE)
                ->first();

            if (! $group instanceof RegistrationGroup) {
                throw RegistrationGroupNotEligibleException::create($registrationGroupId);
            }

            $previousSlotOfGroup = BracketSlot::query()
                ->where('event_id', $locked->id)
                ->where('registration_group_id', $group->id)
                ->where('id', '!=', $targetSlot->id)
                ->first();

            if ($previousSlotOfGroup instanceof BracketSlot) {
                $previousSlotOfGroup->registration_group_id = $targetSlot->registration_group_id;
                $previousSlotOfGroup->is_bye = false;
                $previousSlotOfGroup->save();
            }

            $targetSlot->registration_group_id = $group->id;
            $targetSlot->is_bye = false;
            $targetSlot->save();
        });
    }
}
