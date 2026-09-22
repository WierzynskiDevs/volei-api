<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * Organizador bloqueado tentando operar (BRIEF §35).
 *
 * Bloqueio é ação administrativa auditada: descumprir obrigação de reembolso
 * pode levar a ele. Enquanto durar, o organizador não cria nem publica evento.
 */
final class OrganizerCannotOperateException extends DomainException
{
    public function errorCode(): string
    {
        return 'ORGANIZER_BLOCKED';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public static function create(): self
    {
        return new self('Sua conta de organizador está bloqueada. Fale com o suporte.');
    }
}
