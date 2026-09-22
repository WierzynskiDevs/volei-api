<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * O mesmo CPF/CNPJ já está vinculado a outro organizador.
 *
 * Defesa contra a mesma pessoa/empresa abrir várias contas — a unicidade real é
 * o índice parcial único em `document_number_hash` (CLAUDE.md §6); esta exceção
 * só dá mensagem legível antes de bater na constraint.
 */
final class DuplicateOrganizerDocumentException extends DomainException
{
    public function errorCode(): string
    {
        return 'ORGANIZER_DOCUMENT_ALREADY_LINKED';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function create(): self
    {
        return new self('Este CPF/CNPJ já está vinculado a outra conta de organizador.');
    }
}
