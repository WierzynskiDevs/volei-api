<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * Token de convite de juiz inválido (ADR 0013 §5).
 *
 * "Não encontrado" e "expirado" devolvem a MESMA mensagem genérica — não é
 * útil ao juiz distinguir, e não vaza se o token chegou a existir de fato.
 * "Já usado" é informação diferente (o convite foi válido, só que já
 * consumido) e por isso tem status e mensagem próprios.
 */
final class InvalidRefereeInvitationException extends DomainException
{
    private int $status = 404;

    public function errorCode(): string
    {
        return 'REFEREE_INVITATION_INVALID';
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public static function notFoundOrExpired(): self
    {
        $exception = new self('Este convite não é mais válido. Peça um novo link ao organizador.');
        $exception->status = 404;

        return $exception;
    }

    public static function alreadyUsed(): self
    {
        $exception = new self('Este convite já foi usado.');
        $exception->status = 409;

        return $exception;
    }
}
