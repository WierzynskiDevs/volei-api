<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Exceptions;

use App\Modules\Operations\Domain\Enums\MatchStatus;
use App\Shared\Domain\Exceptions\DomainException;

/**
 * Set gravado fora da janela permitida (ADR 0013 §4, S9): só a partir de
 * `EM_ANDAMENTO` (juiz iniciou) ou, para correção, em `FINALIZADA`. Antes
 * disso não existe placar para lançar; depois de `CANCELADA` não faz sentido.
 */
final class MatchSetsNotRecordableException extends DomainException
{
    public function errorCode(): string
    {
        return 'MATCH_SETS_NOT_RECORDABLE';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function create(MatchStatus $current): self
    {
        return (new self(
            "Não é possível registrar set com a partida em \"{$current->label()}\"."
        ))->withContext(['current_status' => $current->value]);
    }
}
