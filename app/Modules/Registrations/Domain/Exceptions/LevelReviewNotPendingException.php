<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Domain\Exceptions;

use App\Modules\Registrations\Domain\Enums\LevelReview;
use App\Shared\Domain\Exceptions\DomainException;

/**
 * Só revisão pendente aceita decisão.
 *
 * Fecha a janela de duplo clique em "Aprovar"/"Reprovar": dois cliques
 * simultâneos produzem uma decisão e um 409, nunca duas decisões nem sobrescrita
 * silenciosa de quem decidiu primeiro. O aditivo §17 exige registrar decisão,
 * usuário e data — sobrescrever apagaria justamente essa trilha.
 */
final class LevelReviewNotPendingException extends DomainException
{
    public function errorCode(): string
    {
        return 'REGISTRATION_LEVEL_REVIEW_NOT_PENDING';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function from(LevelReview $current): self
    {
        return (new self(
            $current === LevelReview::NOT_REQUIRED
                ? 'Esta inscrição não precisa de análise de nível.'
                : "Esta análise já foi decidida ({$current->label()})."
        ))->withContext(['current_level_review' => $current->value]);
    }
}
