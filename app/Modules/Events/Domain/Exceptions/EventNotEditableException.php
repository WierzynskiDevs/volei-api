<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Exceptions;

use App\Modules\Events\Domain\Enums\EventStatus;
use App\Shared\Domain\Exceptions\DomainException;

/**
 * Evento em estado terminal não é editado.
 *
 * Editar um evento finalizado reescreveria o histórico de quem participou;
 * editar um cancelado alteraria a base de um reembolso já devido.
 */
final class EventNotEditableException extends DomainException
{
    public function errorCode(): string
    {
        return 'EVENT_NOT_EDITABLE';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function from(EventStatus $status): self
    {
        return (new self(
            "Um evento em \"{$status->label()}\" não pode mais ser alterado."
        ))->withContext(['current_status' => $status->value]);
    }
}
