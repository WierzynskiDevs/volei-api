<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Controllers;

use App\Modules\Operations\Application\FinishMatchAction;
use App\Modules\Operations\Application\RecordMatchSetAction;
use App\Modules\Operations\Application\StartMatchAction;
use App\Modules\Operations\Http\Requests\RecordMatchSetRequest;
use App\Modules\Operations\Http\Resources\MatchResource;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Users\Infrastructure\Models\User;
use App\Shared\Http\Exceptions\MissingIdempotencyKeyException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * "Minhas partidas" do juiz (ADR 0013 §5/§7/§8 — S9). Consumidor: `/juiz` no
 * volei-app.
 *
 * Autorização inteira pela `referee-session` (a query já filtra por
 * `referee_id` do token — não existe caminho para ver partida alheia, então
 * não há Policy nem checagem extra aqui, igual ao raciocínio do ADR 0013 §7).
 * Para as ações de escrita (iniciar/placar/finalizar), a checagem explícita
 * de posse (`assertOwnsMatch`) é quem cumpre esse papel, já que aqui o
 * `{match}` não vem filtrado por `referee_id` na query.
 *
 * Reaproveita `MatchResource` (não o público/`PublicMatchResource`): o juiz
 * está operando a própria partida, tem direito ao mesmo nível de detalhe que
 * o organizador vê (`referee_name` é o próprio nome dele).
 */
final class RefereeMatchController
{
    public function __construct(
        private readonly StartMatchAction $start,
        private readonly RecordMatchSetAction $recordSet,
        private readonly FinishMatchAction $finish,
    ) {}

    /** `GET /referee/matches` */
    public function index(Request $request): AnonymousResourceCollection
    {
        $referee = $this->refereeOf($request);

        $matches = GameMatch::query()
            ->where('referee_id', $referee->id)
            ->with(['court', 'referee', 'teamA.registrations.user', 'teamB.registrations.user', 'sets'])
            ->orderBy('scheduled_at')
            ->get();

        return MatchResource::collection($matches);
    }

    /** `POST /referee/matches/{match}/start` — exige `Idempotency-Key`. */
    public function start(Request $request, GameMatch $match): JsonResponse
    {
        $referee = $this->refereeOf($request);
        $this->assertOwnsMatch($match, $referee);

        $match = $this->start->execute(
            $match,
            $this->actorOf($referee),
            $this->idempotencyKeyOf($request),
            CarbonImmutable::now(),
        );

        return MatchResource::make($match)->response();
    }

    /** `PUT /referee/matches/{match}/sets` */
    public function recordSet(RecordMatchSetRequest $request, GameMatch $match): JsonResponse
    {
        $referee = $this->refereeOf($request);
        $this->assertOwnsMatch($match, $referee);

        $this->recordSet->execute(
            $match,
            $this->actorOf($referee),
            $request->setNumber(),
            $request->scoreA(),
            $request->scoreB(),
        );

        return MatchResource::make($match->fresh(['court', 'referee', 'teamA', 'teamB', 'sets']))->response();
    }

    /** `POST /referee/matches/{match}/finish` — exige `Idempotency-Key`. */
    public function finish(Request $request, GameMatch $match): JsonResponse
    {
        $referee = $this->refereeOf($request);
        $this->assertOwnsMatch($match, $referee);

        $match = $this->finish->execute(
            $match,
            $this->actorOf($referee),
            $this->idempotencyKeyOf($request),
            CarbonImmutable::now(),
        );

        return MatchResource::make($match)->response();
    }

    /**
     * `matches` não é sub-recurso de rota do juiz (binding simples de id) —
     * a checagem de posse é explícita aqui, mesmo padrão de
     * `MatchController::assertBelongsToEvent`. 403, não 404: existe a
     * partida, só não pertence a este juiz.
     */
    private function assertOwnsMatch(GameMatch $match, EventReferee $referee): void
    {
        if ($match->referee_id !== $referee->id) {
            abort(403);
        }
    }

    /**
     * Juiz sem conta própria (`user_id` nulo, ADR 0013 §5) não tem `User`
     * para atribuir como autor — `started_by`/`finished_by`/auditoria aceitam
     * `null` de propósito para este caminho. Quando o juiz tem conta
     * vinculada, o autor real fica registrado.
     */
    private function actorOf(EventReferee $referee): ?User
    {
        return $referee->user_id !== null ? User::query()->find($referee->user_id) : null;
    }

    private function refereeOf(Request $request): EventReferee
    {
        /** @var EventReferee $referee */
        $referee = $request->attributes->get('referee');

        return $referee;
    }

    private function idempotencyKeyOf(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            throw MissingIdempotencyKeyException::create();
        }

        return mb_substr($key, 0, 128);
    }
}
