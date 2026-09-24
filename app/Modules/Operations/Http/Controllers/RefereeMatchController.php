<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Controllers;

use App\Modules\Operations\Http\Resources\MatchResource;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * "Minhas partidas" do juiz (ADR 0013 §5/§7/§8 — S9). Consumidor: `/juiz` no
 * volei-app.
 *
 * Autorização inteira pela `referee-session` (a query já filtra por
 * `referee_id` do token — não existe caminho para ver partida alheia, então
 * não há Policy nem checagem extra aqui, igual ao raciocínio do ADR 0013 §7).
 *
 * Reaproveita `MatchResource` (não o público/`PublicMatchResource`): o juiz
 * está operando a própria partida, tem direito ao mesmo nível de detalhe que
 * o organizador vê (`referee_name` é o próprio nome dele).
 */
final class RefereeMatchController
{
    /** `GET /referee/matches` */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var EventReferee $referee */
        $referee = $request->attributes->get('referee');

        $matches = GameMatch::query()
            ->where('referee_id', $referee->id)
            ->with(['court', 'referee', 'teamA.registrations.user', 'teamB.registrations.user', 'sets'])
            ->orderBy('scheduled_at')
            ->get();

        return MatchResource::collection($matches);
    }
}
