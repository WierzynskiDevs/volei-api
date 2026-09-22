<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/** Um usuário tem no máximo um perfil de organizador (`organizers.user_id` é unique). */
final class OrganizerAlreadyExistsException extends DomainException
{
    public function errorCode(): string
    {
        return 'ORGANIZER_ALREADY_EXISTS';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function create(): self
    {
        return new self('Este usuário já tem um perfil de organizador.');
    }
}
