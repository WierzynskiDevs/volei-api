<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

final class EmailAlreadyRegisteredException extends DomainException
{
    public function errorCode(): string
    {
        return 'EMAIL_ALREADY_REGISTERED';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function for(string $email): self
    {
        // O e-mail não entra no contexto: a resposta não deve servir de oráculo
        // para enumerar contas cadastradas.
        return new self('Este e-mail já está em uso.');
    }
}
