<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Application;

use App\Modules\Brackets\Domain\Exceptions\DrawNotEditableException;
use App\Modules\Brackets\Infrastructure\Models\BracketSlot;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Registrations\Domain\Enums\GroupStatus;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use Illuminate\Support\Facades\DB;

/**
 * Sorteio aleatório das posições (ADR 0011).
 *
 * Idempotente enquanto o evento estiver em `AWAITING_DRAW`: pode ser chamado
 * de novo antes de publicar, e reembaralha do zero — não é cumulativo com um
 * encaixe manual anterior.
 *
 * O tamanho da chave foi fixado em `StartDrawAction`. Se novas duplas
 * completarem depois do início do sorteio, elas não recebem posição — o
 * organizador reinicia o sorteio (não implementado nesta fatia) se quiser
 * incluí-las. Documentado como fora de escopo no ADR 0011.
 */
final readonly class RandomizeDrawAction
{
    public function execute(Event $event): void
    {
        DB::transaction(function () use ($event): void {
            /** @var Event $locked */
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== EventStatus::AWAITING_DRAW) {
                throw DrawNotEditableException::forStatus($locked->status);
            }

            $slots = BracketSlot::query()
                ->where('event_id', $locked->id)
                ->orderBy('position')
                ->get();

            $groups = RegistrationGroup::query()
                ->where('event_id', $locked->id)
                ->where('status', GroupStatus::COMPLETE)
                ->get()
                ->shuffle()
                ->values();

            /*
             * Limpa tudo antes de reatribuir: reescrever slot a slot sem
             * este passo pode gravar uma dupla numa posição enquanto ela
             * ainda "existe" noutra ainda não processada, e o unique parcial
             * `bracket_slots_group_unique` (não adiável) rejeita a escrita
             * intermediária mesmo dentro da mesma transação.
             */
            BracketSlot::query()->where('event_id', $locked->id)->update([
                'registration_group_id' => null,
                'is_bye' => false,
            ]);

            foreach ($slots as $index => $slot) {
                $groupId = $groups->get($index)?->id;

                if ($groupId !== null) {
                    BracketSlot::query()->whereKey($slot->id)->update(['registration_group_id' => $groupId]);
                }
            }
        });
    }
}
