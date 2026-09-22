<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * A dupla indicada não pode entrar na partida (ADR 0013 §4, S9): não
 * pertence a este evento, não está `COMPLETE` (ADR 0001), ou é a mesma dos
 * dois lados. 422 — mesmo padrão de `RegistrationGroupNotEligibleException`
 * (Brackets): o problema é o dado enviado, não o estado da partida.
 */
final class MatchTeamNotEligibleException extends DomainException
{
    public function errorCode(): string
    {
        return 'MATCH_TEAM_NOT_ELIGIBLE';
    }

    public static function create(string $groupId): self
    {
        return (new self(
            'Esta dupla não está apta para a partida — precisa pertencer a este evento e estar confirmada.'
        ))->withContext(['registration_group_id' => $groupId]);
    }

    public static function sameTeamTwice(): self
    {
        return new self('As duas duplas da partida não podem ser a mesma.');
    }
}
