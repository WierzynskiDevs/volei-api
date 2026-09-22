<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Controllers;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Application\SetCourtsAction;
use App\Modules\Operations\Http\Requests\SetCourtsRequest;
use App\Modules\Operations\Http\Resources\CourtResource;
use App\Modules\Operations\Infrastructure\Models\Court;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Quadras do evento (ADR 0013 §2/§8 — S8a).
 *
 * Consumidor: `/organizador/quadras/{slug}` (tela nova, ainda não construída
 * no volei-app — ver docs/DIVERGENCES.md). Autorização é a mesma de editar o
 * evento: configurar quadra é configuração do evento, não ação
 * administrativa separada.
 */
final class OrganizerCourtController
{
    public function __construct(private readonly SetCourtsAction $setCourts) {}

    /** `GET /organizer/events/{slug}/courts` */
    public function index(Event $event): AnonymousResourceCollection
    {
        Gate::authorize('update', $event);

        $courts = Court::query()
            ->where('event_id', $event->id)
            ->orderBy('position')
            ->get();

        return CourtResource::collection($courts);
    }

    /** `PUT /organizer/events/{slug}/courts` — substitui o conjunto inteiro. */
    public function replace(SetCourtsRequest $request, Event $event): AnonymousResourceCollection
    {
        Gate::authorize('update', $event);

        $courts = $this->setCourts->execute(
            event: $event,
            actor: $this->userOf($request),
            labels: $request->labels(),
        );

        return CourtResource::collection($courts);
    }

    private function userOf(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
