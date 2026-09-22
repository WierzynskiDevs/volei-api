<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Domain\Exceptions;

use App\Modules\Registrations\Domain\Enums\RegistrationStatus;
use App\Shared\Domain\Exceptions\DomainException;

/**
 * Transição de estado inválida na inscrição.
 *
 * CLAUDE.md §8: transição inválida lança exceção de domínio — não é ignorada e
 * não "corrige" o estado. Confirmar uma inscrição já cancelada, por exemplo,
 * teria de ser um erro visível, não um no-op silencioso que devolve 200.
 */
final class InvalidRegistrationTransitionException extends DomainException
{
    public function errorCode(): string
    {
        return 'REGISTRATION_INVALID_TRANSITION';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function from(RegistrationStatus $current, RegistrationStatus $target): self
    {
        return (new self(
            "Uma inscrição em \"{$current->label()}\" não pode ir para \"{$target->label()}\"."
        ))->withContext([
            'current_status' => $current->value,
            'target_status' => $target->value,
        ]);
    }
}
