<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * Não há mais vaga no evento.
 *
 * Lançada de dentro da transação que já tem lock na linha do evento (ADR 0003):
 * se esta exceção sai, é porque a contagem sob lock não deixou espaço — não
 * porque uma leitura otimista chegou atrasada.
 */
final class EventFullException extends DomainException
{
    public function errorCode(): string
    {
        // Código estável já previsto como exemplo no CLAUDE.md §13.
        return 'REGISTRATION_EVENT_FULL';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function forEvent(string $slug, int $maxTeams): self
    {
        return (new self(
            'As vagas deste campeonato acabaram.'
        ))->withContext(['event_slug' => $slug, 'max_teams' => $maxTeams]);
    }
}
