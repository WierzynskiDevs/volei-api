<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Controllers;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Application\AssignMatchCourtAction;
use App\Modules\Operations\Application\AssignMatchRefereeAction;
use App\Modules\Operations\Application\CancelMatchAction;
use App\Modules\Operations\Application\CreateMatchAction;
use App\Modules\Operations\Application\FinishMatchAction;
use App\Modules\Operations\Application\RecordMatchSetAction;
use App\Modules\Operations\Application\StartMatchAction;
use App\Modules\Operations\Http\Requests\AssignMatchCourtRequest;
use App\Modules\Operations\Http\Requests\AssignMatchRefereeRequest;
use App\Modules\Operations\Http\Requests\CreateMatchRequest;
use App\Modules\Operations\Http\Requests\RecordMatchSetRequest;
use App\Modules\Operations\Http\Resources\MatchResource;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Users\Infrastructure\Models\User;
use App\Shared\Http\Exceptions\MissingIdempotencyKeyException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Ciclo de vida da partida — kanban do organizador (ADR 0013 §4/§7/§8 — S9).
 *
 * Escopo desta fatia: todo o ciclo (criar, atribuir quadra/juiz, iniciar,
 * registrar set, finalizar, cancelar) operado pelo organizador, autorizado
 * pela mesma `EventPolicy::update` de quadras/juízes. O acesso direto do
 * juiz pela sessão escopada por token (ADR 0013 §5/§7) fica para a fatia
 * seguinte — ainda não existe tela `/juiz` consumindo isto.
 *
 * Controller magro (CLAUDE.md §4.3): autoriza, valida e chama UMA action.
 */
final class MatchController
{
    public function __construct(
        private readonly CreateMatchAction $create,
        private readonly AssignMatchCourtAction $assignCourt,
        private readonly AssignMatchRefereeAction $assignReferee,
        private readonly StartMatchAction $start,
        private readonly RecordMatchSetAction $recordSet,
        private readonly FinishMatchAction $finish,
        private readonly CancelMatchAction $cancel,
    ) {}

    /** `GET /organizer/events/{slug}/matches` — o quadro inteiro, sem paginação (mesmo padrão do sorteio). */
    public function index(Event $event): AnonymousResourceCollection
    {
        Gate::authorize('update', $event);

        $matches = GameMatch::query()
            ->where('event_id', $event->id)
            ->with(['court', 'referee', 'teamA.registrations.user', 'teamB.registrations.user', 'sets'])
            ->orderBy('created_at')
            ->get();

        return MatchResource::collection($matches);
    }

    /** `POST /organizer/events/{slug}/matches` */
    public function store(CreateMatchRequest $request, Event $event): JsonResponse
    {
        Gate::authorize('update', $event);

        $match = $this->create->execute(
            event: $event,
            actor: $this->userOf($request),
            teamAId: $request->teamAId(),
            teamBId: $request->teamBId(),
            phase: $request->phase(),
        );

        return MatchResource::make($match)->response()->setStatusCode(201);
    }

    /** `POST /organizer/events/{slug}/matches/{match}/court` */
    public function assignCourt(AssignMatchCourtRequest $request, Event $event, GameMatch $match): JsonResponse
    {
        Gate::authorize('update', $event);
        $this->assertBelongsToEvent($match, $event);

        $match = $this->assignCourt->execute($match, $this->userOf($request), $request->courtId());

        return MatchResource::make($match)->response();
    }

    /** `POST /organizer/events/{slug}/matches/{match}/referee` */
    public function assignReferee(AssignMatchRefereeRequest $request, Event $event, GameMatch $match): JsonResponse
    {
        Gate::authorize('update', $event);
        $this->assertBelongsToEvent($match, $event);

        $match = $this->assignReferee->execute($match, $this->userOf($request), $request->refereeId());

        return MatchResource::make($match)->response();
    }

    /** `POST /organizer/events/{slug}/matches/{match}/start` — exige `Idempotency-Key`. */
    public function start(Request $request, Event $event, GameMatch $match): JsonResponse
    {
        Gate::authorize('update', $event);
        $this->assertBelongsToEvent($match, $event);

        $match = $this->start->execute(
            $match,
            $this->userOf($request),
            $this->idempotencyKeyOf($request),
            CarbonImmutable::now(),
        );

        return MatchResource::make($match)->response();
    }

    /** `PUT /organizer/events/{slug}/matches/{match}/sets` */
    public function recordSet(RecordMatchSetRequest $request, Event $event, GameMatch $match): JsonResponse
    {
        Gate::authorize('update', $event);
        $this->assertBelongsToEvent($match, $event);

        $this->recordSet->execute(
            $match,
            $this->userOf($request),
            $request->setNumber(),
            $request->scoreA(),
            $request->scoreB(),
        );

        return MatchResource::make($match->fresh(['court', 'referee', 'teamA', 'teamB', 'sets']))->response();
    }

    /** `POST /organizer/events/{slug}/matches/{match}/finish` — exige `Idempotency-Key`. */
    public function finish(Request $request, Event $event, GameMatch $match): JsonResponse
    {
        Gate::authorize('update', $event);
        $this->assertBelongsToEvent($match, $event);

        $match = $this->finish->execute(
            $match,
            $this->userOf($request),
            $this->idempotencyKeyOf($request),
            CarbonImmutable::now(),
        );

        return MatchResource::make($match)->response();
    }

    /** `POST /organizer/events/{slug}/matches/{match}/cancel` */
    public function cancel(Request $request, Event $event, GameMatch $match): JsonResponse
    {
        Gate::authorize('update', $event);
        $this->assertBelongsToEvent($match, $event);

        $match = $this->cancel->execute($match, $this->userOf($request));

        return MatchResource::make($match)->response();
    }

    /**
     * `matches` não é sub-recurso de rota (binding simples de id) — a checagem
     * de pertencimento é explícita aqui, mesmo padrão de `OrganizerRefereeController`.
     */
    private function assertBelongsToEvent(GameMatch $match, Event $event): void
    {
        if ($match->event_id !== $event->id) {
            abort(404);
        }
    }

    private function idempotencyKeyOf(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            throw MissingIdempotencyKeyException::create();
        }

        return mb_substr($key, 0, 128);
    }

    private function userOf(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
