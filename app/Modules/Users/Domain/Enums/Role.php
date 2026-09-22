<?php

declare(strict_types=1);

namespace App\Modules\Users\Domain\Enums;

/**
 * Papéis do sistema (BRIEF §14, CLAUDE.md §10).
 *
 * O papel vem SEMPRE do banco. Papel enviado pelo cliente é ignorado.
 * Um usuário pode ter mais de um papel (o baseline tem contas PLAYER + ORGANIZER).
 */
enum Role: string
{
    case PLAYER = 'PLAYER';
    case ORGANIZER = 'ORGANIZER';
    case SUPER_ADMIN = 'SUPER_ADMIN';

    /** Rótulo em pt-BR, espelhando `ROLE_LABEL` do frontend. */
    public function label(): string
    {
        return match ($this) {
            self::PLAYER => 'Jogador',
            self::ORGANIZER => 'Organizador',
            self::SUPER_ADMIN => 'Super Admin',
        };
    }

    /**
     * Rota inicial do papel, espelhando `homeForRole()` do frontend
     * (`src/lib/session.tsx`). Mantido no backend para que a decisão de
     * destino após login não dependa do cliente.
     */
    public function homePath(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => '/admin',
            self::ORGANIZER => '/organizador',
            self::PLAYER => '/',
        };
    }

    /** Papel que um usuário recém-cadastrado recebe. */
    public static function default(): self
    {
        return self::PLAYER;
    }

    /**
     * SUPER_ADMIN nunca é atribuível por fluxo público — só por provisionamento
     * administrativo explícito e auditado.
     */
    public function isSelfAssignable(): bool
    {
        return $this !== self::SUPER_ADMIN;
    }
}
