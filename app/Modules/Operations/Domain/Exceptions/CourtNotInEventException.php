<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * A quadra indicada não pertence ao evento do juiz (ADR 0013 §8).
 *
 * 422, mesmo padrão de `RegistrationGroupNotEligibleException` (Brackets):
 * o problema é o dado enviado, não o estado da entidade em si — e é a
 * defesa contra atribuir quadra de outro evento por IDOR de parâmetro.
 */
final class CourtNotInEventException extends DomainException
{
    public function errorCode(): string
    {
        return 'COURT_NOT_IN_EVENT';
    }

    public static function create(string $courtId): self
    {
        return (new self('Esta quadra não pertence a este evento.'))
            ->withContext(['court_id' => $courtId]);
    }
}
