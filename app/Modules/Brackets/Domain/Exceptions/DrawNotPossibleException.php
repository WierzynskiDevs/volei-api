<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Domain\Exceptions;

use App\Modules\Events\Domain\Enums\EventStatus;
use App\Shared\Domain\Exceptions\DomainException;

/**
 * O sorteio não pode começar agora (ADR 0011).
 *
 * 409: o pedido está bem formado, o que está errado é o estado atual do
 * evento — mesmo raciocínio de `InvalidEventTransitionException`.
 */
final class DrawNotPossibleException extends DomainException
{
    public function errorCode(): string
    {
        return 'DRAW_NOT_POSSIBLE';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function wrongStatus(EventStatus $current): self
    {
        return (new self(
            "O sorteio só pode começar com as inscrições encerradas. O evento está \"{$current->label()}\"."
        ))->withContext(['current_status' => $current->value]);
    }

    public static function notEnoughTeams(int $completeTeams): self
    {
        return (new self(
            'É preciso de pelo menos 2 duplas confirmadas para sortear a chave.'
        ))->withContext(['complete_teams' => $completeTeams]);
    }
}
