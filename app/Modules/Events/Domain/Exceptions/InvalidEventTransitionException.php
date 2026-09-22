<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Exceptions;

use App\Modules\Events\Domain\Enums\EventStatus;
use App\Shared\Domain\Exceptions\DomainException;

/**
 * Transição de estado não permitida pela máquina de estados (CLAUDE.md §8).
 *
 * 409 e não 422: o pedido está bem formado, o que está errado é o estado atual
 * do recurso. É o mesmo caso de "publicar um evento já cancelado".
 */
final class InvalidEventTransitionException extends DomainException
{
    public function errorCode(): string
    {
        return 'EVENT_INVALID_TRANSITION';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function from(EventStatus $current, EventStatus $target): self
    {
        return (new self(
            "Não é possível mudar o evento de \"{$current->label()}\" para \"{$target->label()}\"."
        ))->withContext([
            'current_status' => $current->value,
            'target_status' => $target->value,
            'allowed' => array_map(
                fn (EventStatus $s): string => $s->value,
                $current->allowedTransitions(),
            ),
        ]);
    }
}
