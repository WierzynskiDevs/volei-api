<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Application;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Contagem de vagas ocupadas de um evento (ADR 0003).
 *
 * Vive em `Application` e não em `Domain` porque precisa consultar o banco —
 * `Domain` não faz IO (CLAUDE.md §4.3).
 *
 * ## A unidade de ocupação é a DUPLA, não o atleta
 *
 * `events.max_teams` é teto de **duplas** e a tela mostra "16 / 16 duplas", mas
 * a inscrição é por jogador (ADR 0001). A conversão é:
 *
 *   vagas ocupadas = (duplas com ao menos uma inscrição ocupando vaga)
 *                  + (inscrições sem dupla que ocupam vaga)
 *
 * A segunda parcela é **conservadora de propósito**: dois atletas que se
 * inscreveram sozinhos ("encontrar parceiro" / "individual") contam como duas
 * vagas até formarem dupla. Isso pode encher o evento antes do teto real de
 * atletas, mas nunca causa overbooking — e o pareamento posterior é Fase 2
 * (ADR 0001: "o grupo é formado depois"). Errar para o lado de sobrar vaga é
 * recuperável; errar para o lado de vender vaga que não existe não é.
 *
 * ## Por que recomputar em vez de incrementar
 *
 * `events.teams_registered_count` é contador desnormalizado. Recomputar sob o
 * lock da linha do evento é O(inscrições do evento) — irrelevante nesta escala —
 * e é imune a incremento perdido, a reserva que venceu no meio do caminho e a
 * job de expiração rodando em paralelo. Um `+1`/`-1` espalhado por cinco
 * actions divergiria do real na primeira exceção.
 */
final readonly class EventOccupancy
{
    /**
     * Trava a linha do evento para o resto da transação.
     *
     * `SELECT ... FOR UPDATE` (CLAUDE.md §8). Toda contagem e todo insert de
     * inscrição acontecem depois disto e dentro da mesma transação — contar
     * antes do lock é contar um número que já mudou.
     *
     * Recebe o **id** e não o model de propósito: quem chama a partir de uma
     * inscrição tem `registrations.event_id` garantido pela FK, enquanto
     * `$registration->event` é relação nullable. Além disso o evento é sempre
     * relido — o que o controller carregou foi lido fora da transação e pode
     * estar desatualizado.
     */
    public function lock(string $eventId): Event
    {
        /** @var Event $locked */
        $locked = Event::query()
            ->whereKey($eventId)
            ->lockForUpdate()
            ->firstOrFail();

        return $locked;
    }

    /**
     * Vagas ocupadas agora. Só faz sentido chamada dentro da transação que já
     * chamou `lock()`.
     */
    public function occupiedSlots(Event $event, CarbonImmutable $now): int
    {
        $groups = Registration::query()
            ->where('event_id', $event->id)
            ->whereNotNull('group_id')
            ->occupying($now)
            ->distinct()
            ->count('group_id');

        $solo = Registration::query()
            ->where('event_id', $event->id)
            ->whereNull('group_id')
            ->occupying($now)
            ->count();

        return $groups + $solo;
    }

    /**
     * Ainda cabe `$slots` vaga(s)?
     *
     * `max_teams IS NULL` = sem teto (docs/DIVERGENCES.md §6): a trava de última
     * vaga não se aplica, mas a de duplicidade continua valendo (ADR 0003).
     */
    public function hasRoomFor(Event $event, CarbonImmutable $now, int $slots = 1): bool
    {
        if ($event->max_teams === null) {
            return true;
        }

        return $this->occupiedSlots($event, $now) + $slots <= $event->max_teams;
    }

    /**
     * Sincroniza o contador desnormalizado que as telas exibem.
     *
     * Escreve direto com query builder em vez de `$event->save()`: o contador
     * não está em `$fillable` de propósito (CLAUDE.md §5) e não deve virar
     * atributo mexível por update de perfil de evento.
     */
    public function syncCounter(Event $event, CarbonImmutable $now): int
    {
        $count = $this->occupiedSlots($event, $now);

        DB::table('events')
            ->where('id', $event->id)
            ->update(['teams_registered_count' => $count]);

        $event->teams_registered_count = $count;

        return $count;
    }
}
