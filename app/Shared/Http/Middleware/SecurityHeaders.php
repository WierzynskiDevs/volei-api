<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers (CLAUDE.md §11).
 *
 * A API só devolve JSON, então o conjunto aqui é o que faz sentido para uma
 * resposta de dados. A CSP da interface é responsabilidade do volei-app.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Resposta de API nunca deve ser cacheada por intermediário: pode conter
        // dado pessoal ou financeiro.
        $response->headers->set('Cache-Control', 'no-store, private');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
