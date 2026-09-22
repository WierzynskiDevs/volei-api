<?php

declare(strict_types=1);

use App\Modules\Administration\Http\Middleware\EnsureSuperAdmin;
use App\Shared\Http\ApiExceptionRenderer;
use App\Shared\Http\Middleware\AssignRequestId;
use App\Shared\Http\Middleware\EnsureAccountIsActive;
use App\Shared\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Autenticação por cookie de sessão httpOnly (ADR 0005).
        // Sem isto, o cookie emitido pela API não autentica o SPA.
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
            AssignRequestId::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            /*
             * Conta suspensa não opera, nem com sessão já aberta (ADR 0010 §3).
             * Aplicado junto de `auth:sanctum` nas rotas autenticadas — precisa
             * rodar DEPOIS do guard, senão não há usuário para verificar.
             */
            'active-account' => EnsureAccountIsActive::class,

            // Primeira camada do painel administrativo (ADR 0010 §4).
            'super-admin' => EnsureSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Envelope de erro único (CLAUDE.md §13).
        $exceptions->render(new ApiExceptionRenderer);

        // Estes campos jamais entram em relatório de exceção (CLAUDE.md §21).
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'phone',
            'document_number',
        ]);
    })->create();
