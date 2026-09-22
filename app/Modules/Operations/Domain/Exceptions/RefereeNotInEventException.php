<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/** O juiz indicado não pertence ao evento da partida — mesmo padrão de `CourtNotInEventException`. */
final class RefereeNotInEventException extends DomainException
{
    public function errorCode(): string
    {
        return 'REFEREE_NOT_IN_EVENT';
    }

    public static function create(string $refereeId): self
    {
        return (new self('Este juiz não pertence a este evento.'))
            ->withContext(['referee_id' => $refereeId]);
    }
}
