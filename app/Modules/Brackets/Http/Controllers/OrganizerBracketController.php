<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Http\Controllers;

use App\Modules\Brackets\Application\BracketDirectory;
use App\Modules\Brackets\Application\DTO\BracketView;
use App\Modules\Brackets\Application\PlaceGroupInSlotAction;
use App\Modules\Brackets\Application\PublishBracketAction;
use App\Modules\Brackets\Application\RandomizeDrawAction;
use App\Modules\Brackets\Application\StartDrawAction;
use App\Modules\Brackets\Http\Requests\PlaceGroupInSlotRequest;
use App\Modules\Brackets\Http\Resources\BracketResource;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Sorteio/chaveamento inicial (ADR 0011) — consumidor:
 * `/organizador/sorteio/{slug}`.
 *
 * Controller magro (CLAUDE.md §4.3): autoriza, valida e chama UMA action.
 * Reaproveita `EventPolicy::changeStatus` — é literalmente "o organizador dono
 * do evento, ou super admin", a mesma regra de publish/cancel.
 */
final class OrganizerBracketController
{
    public function __construct(private readonly BracketDirectory $directory) {}

    public function show(Event $event): JsonResponse
    {
        Gate::authorize('changeStatus', $event);

        return $this->response($event);
    }

    public function start(Request $request, Event $event, StartDrawAction $action): JsonResponse
    {
        Gate::authorize('changeStatus', $event);

        $event = $action->execute($event, $this->userOf($request));

        return $this->response($event);
    }

    public function randomize(Event $event, RandomizeDrawAction $action): JsonResponse
    {
        Gate::authorize('changeStatus', $event);

        $action->execute($event);

        return $this->response($event);
    }

    /**
     * `$position` chega como string do roteador (`->whereNumber` só valida o
     * formato); convertido aqui porque o arquivo é `strict_types=1` e PHP não
     * faz coerção implícita de string para `int` no parâmetro da action.
     */
    public function placeGroup(
        PlaceGroupInSlotRequest $request,
        Event $event,
        string $position,
        PlaceGroupInSlotAction $action,
    ): JsonResponse {
        Gate::authorize('changeStatus', $event);

        /** @var string|null $registrationGroupId */
        $registrationGroupId = $request->validated('registration_group_id');

        $action->execute($event, (int) $position, $registrationGroupId);

        return $this->response($event);
    }

    public function publish(Request $request, Event $event, PublishBracketAction $action): JsonResponse
    {
        Gate::authorize('changeStatus', $event);

        $event = $action->execute($event, $this->userOf($request));

        return $this->response($event);
    }

    private function response(Event $event): JsonResponse
    {
        $view = new BracketView(
            event: $event,
            slots: $this->directory->slotsFor($event),
            eligibleGroups: $this->directory->eligibleGroupsFor($event),
        );

        return BracketResource::make($view)->response();
    }

    private function userOf(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
