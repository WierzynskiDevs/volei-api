<?php

declare(strict_types=1);

namespace App\Modules\Users\Application;

/**
 * Contagens da base de usuários para o dashboard global.
 *
 * DTO `readonly` em vez de array associativo: o dashboard soma dinheiro ao lado
 * destes números, e array solto é onde uma chave escrita errada vira zero
 * silencioso na tela (CLAUDE.md §5).
 */
final readonly class UserCounts
{
    public function __construct(
        public int $total,
        public int $players,
        public int $organizers,
        public int $blocked,
    ) {}
}
