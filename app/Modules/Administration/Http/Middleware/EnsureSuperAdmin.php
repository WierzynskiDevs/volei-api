<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Middleware;

use App\Modules\Users\Infrastructure\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Primeira camada de autorização do painel administrativo (ADR 0010 §4).
 *
 * O papel vem do banco, sempre: `hasRole()` lê `user_roles`. Papel enviado pelo
 * cliente é ignorado, e nenhuma rota daqui depende de `localStorage`, de botão
 * escondido ou de URL secreta (CLAUDE.md §10 e §28).
 *
 * Esta é a primeira camada, não a única: as policies continuam valendo dentro
 * dos endpoints. Middleware sozinho seria ponto único de falha — bastaria
 * alguém registrar uma rota fora do grupo.
 */
final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        /*
         * 403 e não 404: a existência do painel não é segredo, e esconder o
         * recurso não protege nada — a proteção é a checagem, que já aconteceu.
         * A mensagem é genérica de propósito (§11): não diz o que existe do
         * outro lado.
         */
        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            return new JsonResponse([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Esta área é restrita à administração da plataforma.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
