<?php

declare(strict_types=1);

namespace App\Modules\Users\Domain\Enums;

/**
 * Status da conta.
 *
 * O baseline usa "ATIVO" | "SUSPENSO" (`src/lib/accounts.ts`) e a tela de
 * Super Admin prevê bloqueio de contas (BRIEF §40). BLOCKED é o estado
 * aplicado por ação administrativa auditada.
 */
enum UserStatus: string
{
    case ACTIVE = 'ACTIVE';
    case BLOCKED = 'BLOCKED';

    /** Rótulo em pt-BR, espelhando o vocabulário do frontend. */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Ativo',
            self::BLOCKED => 'Suspenso',
        };
    }

    /** Conta bloqueada não autentica e não cria recurso algum. */
    public function canAuthenticate(): bool
    {
        return $this === self::ACTIVE;
    }
}
