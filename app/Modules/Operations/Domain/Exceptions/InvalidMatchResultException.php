<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * Tentativa de finalizar a partida sem que os sets registrados definam um
 * vencedor pela regra de `best_of_sets` do evento (ADR 0013 §4, S9).
 */
final class InvalidMatchResultException extends DomainException
{
    public function errorCode(): string
    {
        return 'MATCH_RESULT_NOT_DECIDED';
    }

    public static function create(): self
    {
        return new self(
            'Os sets registrados ainda não definem um vencedor. Registre o placar que falta antes de finalizar.'
        );
    }
}
