<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Controllers;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Application\AddRefereeAction;
use App\Modules\Operations\Application\InviteRefereeAction;
use App\Modules\Operations\Application\SetRefereeCourtAction;
use App\Modules\Operations\Http\Requests\AddRefereeRequest;
use App\Modules\Operations\Http\Requests\SetRefereeCourtRequest;
use App\Modules\Operations\Http\Resources\RefereeResource;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Juízes do evento — lado do organizador (ADR 0013 §5/§6/§8 — S8b).
 *
 * Consumidor: `RefereesPanel`/`InviteRefereeDialog` em `/organizador/controle`
 * (hoje mock, `useOperations()` de `lib/operations.tsx`).
 *
 * Autorização é a mesma de editar o evento (`EventPolicy::update`) — mesma
 * decisão já tomada para quadras em S8a: cadastrar/convidar juiz é
 * configuração do evento, não ação administrativa separada.
 */
final class OrganizerRefereeController
{
    public function __construct(
        private readonly AddRefereeAction $addReferee,
        private readonly InviteRefereeAction $inviteReferee,
        private readonly SetRefereeCourtAction $setCourt,
    ) {}

    /** `GET /organizer/events/{slug}/referees` */
    public function index(Event $event): AnonymousResourceCollection
    {
        Gate::authorize('update', $event);

        $referees = EventReferee::query()
            ->where('event_id', $event->id)
            ->orderBy('created_at')
            ->get();

        return RefereeResource::collection($referees);
    }

    /** `POST /organizer/events/{slug}/referees` */
    public function store(AddRefereeRequest $request, Event $event): JsonResponse
    {
        Gate::authorize('update', $event);

        $referee = $this->addReferee->execute(
            event: $event,
            actor: $this->userOf($request),
            name: $request->nameInput(),
            phone: $request->phoneInput(),
        );

        return RefereeResource::make($referee)->response()->setStatusCode(201);
    }

    /**
     * `POST /organizer/events/{slug}/referees/{referee}/invite`
     *
     * Único momento em que o token bruto do convite aparece na resposta —
     * depois disso só o hash existe no banco (ADR 0013 §5).
     */
    public function invite(Request $request, Event $event, EventReferee $referee): JsonResponse
    {
        Gate::authorize('update', $event);
        $this->assertBelongsToEvent($referee, $event);

        $result = $this->inviteReferee->execute(
            referee: $referee,
            actor: $this->userOf($request),
            now: CarbonImmutable::now(),
        );

        return new JsonResponse([
            'data' => [
                'referee' => RefereeResource::make($result['referee']),
                'invite_token' => $result['token'],
            ],
        ]);
    }

    /** `PATCH /organizer/events/{slug}/referees/{referee}` — atribui/remove quadra. */
    public function update(SetRefereeCourtRequest $request, Event $event, EventReferee $referee): JsonResponse
    {
        Gate::authorize('update', $event);
        $this->assertBelongsToEvent($referee, $event);

        $referee = $this->setCourt->execute(
            referee: $referee,
            actor: $this->userOf($request),
            courtId: $request->courtId(),
        );

        return RefereeResource::make($referee)->response();
    }

    /**
     * `event_referees` não é sub-recurso de rota (`{event:slug}/referees/{referee}`
     * usa binding simples de id) — a checagem de pertencimento é explícita aqui,
     * mesmo padrão que `PlaceGroupInSlotAction` usa para dupla fora do evento.
     */
    private function assertBelongsToEvent(EventReferee $referee, Event $event): void
    {
        if ($referee->event_id !== $event->id) {
            abort(404);
        }
    }

    private function userOf(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
