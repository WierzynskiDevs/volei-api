<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Application\ChangeEventStatusAction;
use App\Modules\Events\Application\CreateEventAction;
use App\Modules\Events\Application\PublishEventAction;
use App\Modules\Events\Application\UpdateEventAction;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Http\Requests\CancelEventRequest;
use App\Modules\Events\Http\Requests\SaveEventRequest;
use App\Modules\Events\Http\Resources\EventResource;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Painel do organizador — consumido por `/organizador/eventos`,
 * `/organizador/novo-evento` e `/organizador/alterar-evento/{slug}`.
 *
 * Controller magro (CLAUDE.md §4.3): autoriza, valida e chama UMA action.
 * Toda regra — limite de plano, pré-condição de publicação, máquina de estados —
 * está nas actions, testável sem HTTP.
 */
final class OrganizerEventController
{
    private const int MAX_PER_PAGE = 50;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Event::class);

        $organizer = $this->organizerOf($request);
        $perPage = min($request->integer('per_page', 20), self::MAX_PER_PAGE);

        $events = Event::query()
            ->where('organizer_id', $organizer->id)
            ->with('organizer')
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', EventStatus::from((string) $request->string('status'))->value),
            )
            // O painel é operacional: o próximo evento a acontecer vem primeiro.
            ->orderBy('start_at')
            ->paginate($perPage)
            ->withQueryString();

        return EventResource::collection($events);
    }

    public function store(SaveEventRequest $request, CreateEventAction $action): JsonResponse
    {
        Gate::authorize('create', Event::class);

        $event = $action->execute(
            organizer: $this->organizerOf($request),
            actor: $this->userOf($request),
            data: $request->toData(),
        );

        return EventResource::make($event->load('organizer'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Event $event): JsonResponse
    {
        Gate::authorize('view', $event);

        return EventResource::make($event->load('organizer'))->response();
    }

    public function update(SaveEventRequest $request, Event $event, UpdateEventAction $action): JsonResponse
    {
        Gate::authorize('update', $event);

        $event = $action->execute(
            event: $event,
            actor: $this->userOf($request),
            data: $request->toData(),
            justification: $request->justification(),
        );

        return EventResource::make($event->load('organizer'))->response();
    }

    /** `POST /organizer/events/{slug}/publish` — "Publicar e gerar link". */
    public function publish(Request $request, Event $event, PublishEventAction $action): JsonResponse
    {
        Gate::authorize('changeStatus', $event);

        $event = $action->execute($event->load('organizer'), $this->userOf($request));

        return EventResource::make($event)->response();
    }

    public function openRegistrations(Request $request, Event $event, ChangeEventStatusAction $action): JsonResponse
    {
        return $this->transition($request, $event, $action, EventStatus::REGISTRATION_OPEN);
    }

    public function closeRegistrations(Request $request, Event $event, ChangeEventStatusAction $action): JsonResponse
    {
        return $this->transition($request, $event, $action, EventStatus::REGISTRATION_CLOSED);
    }

    /**
     * Cancelamento exige justificativa: dispara obrigação de reembolso
     * (BRIEF §35/§36) e a razão é evidência, não formalidade.
     */
    public function cancel(CancelEventRequest $request, Event $event, ChangeEventStatusAction $action): JsonResponse
    {
        Gate::authorize('changeStatus', $event);

        $event = $action->execute(
            event: $event->load('organizer'),
            actor: $this->userOf($request),
            target: EventStatus::CANCELLED,
            reason: (string) $request->string('justification'),
        );

        return EventResource::make($event)->response();
    }

    private function transition(
        Request $request,
        Event $event,
        ChangeEventStatusAction $action,
        EventStatus $target,
    ): JsonResponse {
        Gate::authorize('changeStatus', $event);

        $event = $action->execute(
            event: $event->load('organizer'),
            actor: $this->userOf($request),
            target: $target,
        );

        return EventResource::make($event)->response();
    }

    private function userOf(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * Perfil de organizador do usuário logado.
     *
     * Nunca vem da URL nem do corpo da requisição: um `organizer_id` enviado
     * pelo cliente permitiria criar evento no nome de outra pessoa.
     */
    private function organizerOf(Request $request): Organizer
    {
        $user = $this->userOf($request);
        $user->loadMissing('organizer');

        $organizer = $user->organizer;

        if (! $organizer instanceof Organizer) {
            // A Policy já barra o caso normal; isto fecha a janela entre o
            // check e o uso, e mantém o tipo de retorno honesto.
            throw new AccessDeniedHttpException('Esta conta não possui perfil de organizador.');
        }

        return $organizer;
    }
}
