<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * O atleta já tem inscrição ativa neste evento.
 *
 * Vale para duplo clique e para requisições simultâneas: a checagem acontece sob
 * o mesmo lock da contagem de vagas, e o unique parcial em
 * `(event_id, user_id)` é a última linha de defesa (CLAUDE.md §8).
 */
final class DuplicateRegistrationException extends DomainException
{
    public function errorCode(): string
    {
        return 'REGISTRATION_DUPLICATE';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function forUser(string $eventSlug): self
    {
        return (new self(
            'Você já está inscrito neste campeonato.'
        ))->withContext(['event_slug' => $eventSlug]);
    }

    public static function forPartner(string $partnerName): self
    {
        return (new self(
            "{$partnerName} já está inscrito neste campeonato."
        ))->withContext(['reason' => 'partner_already_registered']);
    }
}
