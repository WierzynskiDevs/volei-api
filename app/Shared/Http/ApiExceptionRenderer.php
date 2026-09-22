<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Domain\Exceptions\DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Envelope de erro único da API (CLAUDE.md §13).
 *
 *   { "error": { "code": "...", "message": "...", "details": { ... } } }
 *
 * `code` é estável e consumido por máquina pelo frontend — mudar um código é
 * quebra de contrato e exige versionar a API.
 *
 * Em produção, detalhe interno NUNCA vaza: a mensagem é genérica e o diagnóstico
 * fica no log (CLAUDE.md §11).
 */
final readonly class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $e instanceof DomainException => $this->render(
                $e->errorCode(),
                $e->getMessage(),
                $e->httpStatus(),
                $e->context === [] ? null : $e->context,
            ),

            $e instanceof ValidationException => $this->render(
                'VALIDATION_FAILED',
                'Os dados enviados são inválidos.',
                422,
                $e->errors(),
            ),

            $e instanceof AuthenticationException => $this->render(
                'UNAUTHENTICATED',
                'Autenticação necessária.',
                401,
            ),

            /*
             * `Gate::authorize()` lança AuthorizationException, mas o Laravel a
             * converte em AccessDeniedHttpException antes de chegar aqui. Sem
             * tratar as duas, o 403 sai com o código genérico HTTP_ERROR e a
             * mensagem em inglês do framework — e o frontend não tem código
             * estável para decidir comportamento (CLAUDE.md §13).
             */
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => $this->render(
                'FORBIDDEN',
                'Você não tem permissão para esta ação.',
                403,
            ),

            /*
             * Sessão de SPA expirada. Código próprio porque o frontend reage a
             * ele de forma específica: refaz /sanctum/csrf-cookie e repete a
             * requisição uma vez, em vez de mostrar erro ao usuário.
             */
            $e instanceof TokenMismatchException => $this->render(
                'CSRF_TOKEN_MISMATCH',
                'Sua sessão expirou. Atualize a página e tente novamente.',
                419,
            ),

            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => $this->render(
                'NOT_FOUND',
                'Recurso não encontrado.',
                404,
            ),

            $e instanceof TooManyRequestsHttpException => $this->render(
                'TOO_MANY_REQUESTS',
                'Muitas tentativas. Tente novamente em instantes.',
                429,
            ),

            $e instanceof HttpExceptionInterface => $this->render(
                'HTTP_ERROR',
                $e->getMessage() !== '' ? $e->getMessage() : 'Erro ao processar a requisição.',
                $e->getStatusCode(),
            ),

            default => $this->renderUnexpected($e),
        };
    }

    /**
     * Erro não previsto. Em produção o cliente recebe mensagem genérica —
     * stack trace jamais chega ao usuário (CLAUDE.md §11).
     */
    private function renderUnexpected(Throwable $e): JsonResponse
    {
        $debug = (bool) config('app.debug');

        return $this->render(
            'INTERNAL_ERROR',
            'Erro interno. A equipe foi notificada.',
            500,
            $debug ? [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ] : null,
        );
    }

    /**
     * @param  array<array-key, mixed>|null  $details
     */
    private function render(string $code, string $message, int $status, ?array $details = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }
}
