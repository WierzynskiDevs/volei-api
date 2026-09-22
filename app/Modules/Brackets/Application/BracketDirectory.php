<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Application;

use App\Modules\Brackets\Infrastructure\Models\BracketSlot;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Registrations\Domain\Enums\GroupStatus;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use Illuminate\Support\Collection;

/**
 * Serviço de leitura do módulo (padrão do ADR 0010 §2: outro módulo lê por
 * aqui, nunca com `Model::where()` cruzando fronteira).
 */
final readonly class BracketDirectory
{
    /** @return Collection<int, BracketSlot> */
    public function slotsFor(Event $event): Collection
    {
        return BracketSlot::query()
            ->where('event_id', $event->id)
            ->with('registrationGroup.registrations.user')
            ->orderBy('position')
            ->get();
    }

    /**
     * Duplas `COMPLETE` do evento que ainda não ocupam nenhuma posição —
     * alimenta o seletor de encaixe manual da tela de sorteio.
     *
     * @return Collection<int, RegistrationGroup>
     */
    public function eligibleGroupsFor(Event $event): Collection
    {
        $assignedGroupIds = BracketSlot::query()
            ->where('event_id', $event->id)
            ->whereNotNull('registration_group_id')
            ->pluck('registration_group_id');

        return RegistrationGroup::query()
            ->where('event_id', $event->id)
            ->where('status', GroupStatus::COMPLETE)
            ->whereNotIn('id', $assignedGroupIds)
            ->with('registrations.user')
            ->get();
    }
}
