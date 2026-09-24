<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Controllers;

use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Http\Resources\PublicMatchResource;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Partidas públicas do evento (ADR 0017, Q15). Consumidor: abas "Agenda" e
 * "Resultados" de `eventos.$slug.tsx` no volei-app.
 *
 * Sem `Gate::authorize`, ao contrário de `MatchController::index`. Nunca
 * reaproveita `MatchResource` (expõe `referee_name`, fora da allowlist
 * pública) nem `RefereeResource` (expõe telefone).
 */
final class PublicMatchController
{
    /**
     * Mesmos estados em que a chave é pública (ADR 0011 §7 + ADR 0017).
     *
     * @var list<EventStatus>
     */
    private const array VISIBLE_STATUSES = [
        EventStatus::BRACKET_PUBLISHED,
        EventStatus::IN_PROGRESS,
        EventStatus::FINISHED,
    ];

    public function index(string $slug): AnonymousResourceCollection
    {
        $event = Event::query()
            ->publiclyVisible()
            ->whereIn('status', array_map(fn (EventStatus $status): string => $status->value, self::VISIBLE_STATUSES))
            ->where('slug', $slug)
            ->firstOrFail();

        $matches = GameMatch::query()
            ->where('event_id', $event->id)
            ->with(['court', 'teamA.registrations.user', 'teamB.registrations.user', 'sets'])
            ->orderBy('scheduled_at')
            ->get();

        return PublicMatchResource::collection($matches);
    }
}
