<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * A dupla indicada não pode ser encaixada na chave (ADR 0011): não pertence a
 * este evento, ou não está `COMPLETE` (ADR 0001 — dupla só conta com os dois
 * membros confirmados).
 *
 * 422 e não 409: o problema é o dado enviado (a dupla escolhida), não o estado
 * do sorteio em si.
 */
final class RegistrationGroupNotEligibleException extends DomainException
{
    public function errorCode(): string
    {
        return 'DRAW_GROUP_NOT_ELIGIBLE';
    }

    public static function create(string $registrationGroupId): self
    {
        return (new self(
            'Esta dupla não está apta para o sorteio — precisa pertencer a este evento e estar confirmada.'
        ))->withContext(['registration_group_id' => $registrationGroupId]);
    }
}
