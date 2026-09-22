<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

final class PhoneAlreadyRegisteredException extends DomainException
{
    public function errorCode(): string
    {
        return 'PHONE_ALREADY_REGISTERED';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function create(): self
    {
        return new self('Este telefone já está em uso.');
    }
}
