<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Exceptions;

use App\Modules\Operations\Domain\Enums\MatchStatus;
use App\Shared\Domain\Exceptions\DomainException;

/** Transição de estado inválida na partida (ADR 0013 §3, CLAUDE.md §8). */
final class InvalidMatchTransitionException extends DomainException
{
    public function errorCode(): string
    {
        return 'MATCH_INVALID_TRANSITION';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function from(MatchStatus $current, MatchStatus $target): self
    {
        return (new self(
            "Uma partida em \"{$current->label()}\" não pode ir para \"{$target->label()}\"."
        ))->withContext([
            'current_status' => $current->value,
            'target_status' => $target->value,
        ]);
    }
}
