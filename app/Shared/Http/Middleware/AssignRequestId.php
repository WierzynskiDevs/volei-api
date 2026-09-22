<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlação request → job → webhook (CLAUDE.md §21).
 *
 * Sem um identificador único atravessando as trilhas, investigar um pagamento
 * que falhou obriga a cruzar timestamps na mão.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid7();

        $request->attributes->set('request_id', $requestId);

        // Entra em todo log emitido durante esta requisição.
        Log::shareContext(['request_id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
