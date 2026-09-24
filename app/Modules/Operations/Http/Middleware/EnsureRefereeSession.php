<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Middleware;

use App\Modules\Operations\Infrastructure\Models\EventReferee;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autoriza a sessão do juiz por token (ADR 0013 §5/§7).
 *
 * Deliberadamente **não** é `auth:sanctum`: aquele guard resolve `User` via
 * cookie SPA (ADR 0005) — misturar os dois caminhos no mesmo guard
 * arriscaria um token de juiz autenticar como usuário completo por engano.
 * Aqui o token é resolvido à mão contra `personal_access_tokens`
 * (`PersonalAccessToken::findToken`, o mesmo hash que o Sanctum já usa) e só
 * aceito quando pertence a um `EventReferee` — nunca a um `User`.
 *
 * Não é uma `Policy` do Laravel (ADR 0013 §7): não há `User` autenticado em
 * todo caminho, e a "autorização" aqui é simplesmente "este token pertence a
 * este juiz" — a checagem de que a partida é DELE acontece no controller,
 * igual ao padrão de `MatchController::assertBelongsToEvent`.
 */
final class EnsureRefereeSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return $this->reject();
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken instanceof PersonalAccessToken || ! $accessToken->tokenable instanceof EventReferee) {
            return $this->reject();
        }

        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            return $this->reject();
        }

        $accessToken->forceFill(['last_used_at' => Carbon::now()])->save();

        $request->attributes->set('referee', $accessToken->tokenable);

        return $next($request);
    }

    private function reject(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'REFEREE_SESSION_INVALID',
                'message' => 'Sessão do juiz inválida ou expirada. Abra o link do convite novamente.',
            ],
        ], 401);
    }
}
