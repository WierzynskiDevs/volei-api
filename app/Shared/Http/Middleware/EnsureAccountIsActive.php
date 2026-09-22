<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Modules\Users\Infrastructure\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conta suspensa não opera — nem com sessão já aberta (ADR 0010 §3).
 *
 * ## O buraco que este middleware fecha
 *
 * Até esta fatia, `UserStatus::BLOCKED` era verificado num único ponto:
 * `AuthController::login()`. A conta bloqueada não conseguia **entrar de novo**,
 * mas a sessão aberta antes do bloqueio continuava valendo — o cookie segue
 * válido (ADR 0005) e nenhuma etapa posterior reconsultava o status.
 *
 * Na prática, "suspender conta" seria promessa falsa: a pessoa suspensa seguiria
 * criando evento e se inscrevendo até resolver deslogar. Com o painel
 * administrativo entregando o botão de suspensão (§28), isso deixou de ser
 * hipótese.
 *
 * Roda em toda rota autenticada, depois do `auth`. O custo é nenhum: o usuário
 * já foi carregado pelo guard, e ler uma propriedade do model em memória não
 * acrescenta query.
 */
final class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isBlocked()) {
            /*
             * Encerrar a sessão junto com a recusa: sem isto o cookie continua
             * circulando e cada request seguinte volta a bater aqui. O logout é
             * o mesmo do AuthController — invalidar e rotacionar o token CSRF.
             */
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return new JsonResponse([
                'error' => [
                    'code' => 'ACCOUNT_BLOCKED',
                    'message' => 'Esta conta está suspensa. Fale com o suporte.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
