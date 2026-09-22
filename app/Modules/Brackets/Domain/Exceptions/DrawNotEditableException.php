<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Domain\Exceptions;

use App\Modules\Events\Domain\Enums\EventStatus;
use App\Shared\Domain\Exceptions\DomainException;

/**
 * Sortear, encaixar ou publicar fora de `AWAITING_DRAW` (ADR 0011).
 *
 * Cobre tanto "ainda não iniciado" quanto "já publicado" — nos dois casos a
 * chave não é editável agora, e a mensagem já diz por quê.
 */
final class DrawNotEditableException extends DomainException
{
    public function errorCode(): string
    {
        return 'DRAW_NOT_EDITABLE';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function forStatus(EventStatus $current): self
    {
        $message = $current === EventStatus::BRACKET_PUBLISHED
            ? 'A chave já foi publicada e não pode mais ser editada.'
            : "O sorteio ainda não foi iniciado para este evento (está \"{$current->label()}\").";

        return (new self($message))->withContext(['current_status' => $current->value]);
    }
}
