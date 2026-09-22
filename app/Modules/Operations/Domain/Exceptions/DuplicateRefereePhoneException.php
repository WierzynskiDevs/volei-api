<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/** O telefone já está cadastrado como juiz **deste evento** — dedup por evento, não global. */
final class DuplicateRefereePhoneException extends DomainException
{
    public function errorCode(): string
    {
        return 'REFEREE_PHONE_ALREADY_ADDED';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function create(): self
    {
        return new self('Este telefone já está cadastrado como juiz neste evento.');
    }
}
