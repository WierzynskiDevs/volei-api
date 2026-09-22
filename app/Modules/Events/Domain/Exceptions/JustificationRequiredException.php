<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * Mudança material sem justificativa (BRIEF §35/§36).
 *
 * Alterar data, horário, local ou valor de um evento já publicado gera direito
 * a reembolso para quem se inscreveu. A justificativa é a evidência que
 * sustenta essa conversa — por isso é regra de domínio, e não apenas um campo
 * obrigatório de formulário.
 *
 * 422 com `details` por campo: para a tela, é erro de preenchimento.
 */
final class JustificationRequiredException extends DomainException
{
    public function errorCode(): string
    {
        return 'EVENT_JUSTIFICATION_REQUIRED';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    /**
     * @param  array<int, string>  $changedFields
     */
    public static function forFields(array $changedFields): self
    {
        return (new self(
            'Alterar data, horário, local ou valor de um evento publicado exige justificativa.'
        ))->withContext([
            'justification' => ['Descreva o motivo da alteração — os inscritos serão informados.'],
            'changed_fields' => $changedFields,
        ]);
    }
}
