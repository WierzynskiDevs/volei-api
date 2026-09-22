<?php

declare(strict_types=1);

namespace App\Shared\Http\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * Falta o header `Idempotency-Key` numa operação que o exige (CLAUDE.md §8).
 *
 * 400 e não 422: o problema está no **protocolo** da requisição, não no
 * conteúdo dos campos. Um 422 sugeriria erro de formulário e mandaria o
 * frontend procurar campo inválido que não existe.
 */
final class MissingIdempotencyKeyException extends DomainException
{
    public function errorCode(): string
    {
        return 'IDEMPOTENCY_KEY_REQUIRED';
    }

    public function httpStatus(): int
    {
        return 400;
    }

    public static function create(): self
    {
        return (new self(
            'Esta operação exige o header Idempotency-Key.'
        ))->withContext(['header' => 'Idempotency-Key']);
    }
}
