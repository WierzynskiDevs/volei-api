<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exceptions;

use RuntimeException;

/**
 * Base de toda exceção de domínio.
 *
 * CLAUDE.md §25: exceções de domínio são tipadas e mapeadas para HTTP em um
 * único handler. O `errorCode` é o `code` estável do envelope de erro (§13) —
 * consumido por máquina no frontend, então NUNCA muda sem versionar a API.
 */
abstract class DomainException extends RuntimeException
{
    /** @var array<string, mixed> */
    public array $context = [];

    /** Código estável, consumível por máquina. Ex.: REGISTRATION_EVENT_FULL */
    abstract public function errorCode(): string;

    /** Status HTTP correspondente (CLAUDE.md §13). */
    public function httpStatus(): int
    {
        return 422;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function withContext(array $context): static
    {
        $this->context = $context;

        return $this;
    }
}
