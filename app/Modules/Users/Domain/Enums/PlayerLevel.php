<?php

declare(strict_types=1);

namespace App\Modules\Users\Domain\Enums;

/**
 * Nível técnico declarado pelo atleta (aditivo §16).
 *
 * A coluna `users.level` existe desde a primeira migration, mas era `varchar`
 * sem CHECK e sem enum — string solta onde existe conjunto fechado, o que
 * CLAUDE.md §5 proíbe. Este enum fecha o conjunto.
 *
 * Os rótulos são exatamente os que o baseline usa em `SkillLevel`
 * (`src/lib/mock-data.ts`): a tela já exibe estes quatro valores para jogador.
 * "Livre" não entra aqui de propósito — é categoria de **evento**, não nível de
 * pessoa (`LevelCategory::FREE`).
 *
 * A idade NÃO é nível e não vive aqui: ela é derivada de `users.birth_date`
 * (aditivo §16 — "a idade não é selecionada manualmente").
 */
enum PlayerLevel: string
{
    case BEGINNER = 'BEGINNER';
    case INTERMEDIATE = 'INTERMEDIATE';
    case ADVANCED = 'ADVANCED';
    case OPEN = 'OPEN';

    public function label(): string
    {
        return match ($this) {
            self::BEGINNER => 'Iniciante',
            self::INTERMEDIATE => 'Intermediário',
            self::ADVANCED => 'Avançado',
            self::OPEN => 'Open',
        };
    }

    /**
     * Posição na escala técnica, comparável com
     * `LevelCategory::ceilingRank()`.
     *
     * A ordem é a mesma que o baseline já usa em `/inscricao/{slug}`
     * (`LEVEL_ORDER`), porque é ela que produz o aviso "seu nível está acima da
     * categoria selecionada" que a tela exibe hoje.
     */
    public function rank(): int
    {
        return match ($this) {
            self::BEGINNER => 0,
            self::INTERMEDIATE => 1,
            self::ADVANCED => 2,
            self::OPEN => 3,
        };
    }
}
