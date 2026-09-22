<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Domain\Exceptions;

use App\Modules\Events\Domain\Enums\EventStatus;
use App\Shared\Domain\Exceptions\DomainException;

/**
 * O evento não está em estado que aceite inscrição.
 *
 * Quem decide é `EventStatus::acceptsRegistrations()` — a mesma máquina de
 * estados que a tela já usa para pintar a pílula de status. Rascunho, inscrições
 * encerradas, cancelado e finalizado caem aqui.
 */
final class EventNotAcceptingRegistrationsException extends DomainException
{
    public function errorCode(): string
    {
        return 'REGISTRATION_EVENT_CLOSED';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function inStatus(EventStatus $status): self
    {
        return (new self(
            "Este campeonato não está recebendo inscrições ({$status->label()})."
        ))->withContext(['current_status' => $status->value]);
    }

    /**
     * A janela declarada em `registration_close_at` já passou, ainda que o
     * status não tenha sido movido. A janela é dado do evento (ADR 0002) e vale
     * por si — depender de alguém clicar "encerrar inscrições" deixaria o
     * evento vendendo vaga depois do prazo publicado.
     */
    public static function windowClosed(): self
    {
        return (new self(
            'O prazo de inscrição deste campeonato já encerrou.'
        ))->withContext(['reason' => 'registration_window_closed']);
    }

    public static function windowNotOpen(): self
    {
        return (new self(
            'As inscrições deste campeonato ainda não abriram.'
        ))->withContext(['reason' => 'registration_window_not_open']);
    }
}
