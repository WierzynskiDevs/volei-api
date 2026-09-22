<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * Credenciais inválidas.
 *
 * Mensagem deliberadamente genérica: não revela se o e-mail existe. Um erro
 * distinto para "e-mail não cadastrado" transforma o login em oráculo de
 * enumeração de contas (CLAUDE.md §10/§11).
 */
final class InvalidCredentialsException extends DomainException
{
    public function errorCode(): string
    {
        return 'INVALID_CREDENTIALS';
    }

    public function httpStatus(): int
    {
        return 401;
    }

    public static function create(): self
    {
        return new self('E-mail ou senha incorretos.');
    }
}
