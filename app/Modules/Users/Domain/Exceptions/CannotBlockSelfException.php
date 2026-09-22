<?php

declare(strict_types=1);

namespace App\Modules\Users\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * O super admin tentou bloquear a própria conta.
 *
 * 409 e não 422: não é entrada malformada, é conflito com o estado do sistema —
 * a operação é válida em forma e impossível em consequência (CLAUDE.md §13).
 */
final class CannotBlockSelfException extends DomainException
{
    public static function forUser(string $userId): self
    {
        return (new self('Não é possível bloquear a própria conta.'))
            ->withContext(['user_id' => $userId]);
    }

    public function errorCode(): string
    {
        return 'ADMIN_CANNOT_BLOCK_SELF';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
