<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * Conta bloqueada por ação administrativa (BRIEF §40).
 *
 * Só é lançada DEPOIS de a senha conferir — caso contrário, o erro revelaria
 * a existência da conta a quem não sabe a senha.
 */
final class AccountBlockedException extends DomainException
{
    public function errorCode(): string
    {
        return 'ACCOUNT_BLOCKED';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public static function create(): self
    {
        return new self('Esta conta está suspensa. Fale com o suporte.');
    }
}
