<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Enums;

/**
 * Nível técnico do evento (BRIEF §18, docs/DIVERGENCES.md §3).
 *
 * O BRIEF define BEGINNER, INTERMEDIATE, OPEN e A_PLUS_B. O baseline exibe
 * "Iniciante", "Intermediário", "Avançado", "Open" e "Livre" — ou seja,
 * ADVANCED e FREE existem na tela e não no BRIEF, e A_PLUS_B existe no BRIEF
 * e em nenhuma tela.
 *
 * Decisão (OPEN-QUESTIONS Q4): adotar os nomes do BRIEF e **incluir** os valores
 * que a UI já mostra, para não quebrar nenhuma tela.
 *
 * `A_PLUS_B` entrou em 26/08/2026 por decisão do aditivo §15 (OPEN-QUESTIONS Q11):
 * é categoria própria — evento aberto a duas faixas de nível ao mesmo tempo.
 */
enum LevelCategory: string
{
    case BEGINNER = 'BEGINNER';
    case INTERMEDIATE = 'INTERMEDIATE';
    case ADVANCED = 'ADVANCED';
    case OPEN = 'OPEN';
    case A_PLUS_B = 'A_PLUS_B';
    case FREE = 'FREE';

    public function label(): string
    {
        return match ($this) {
            self::BEGINNER => 'Iniciante',
            self::INTERMEDIATE => 'Intermediário',
            self::ADVANCED => 'Avançado',
            self::OPEN => 'Open',
            self::A_PLUS_B => 'A+B',
            self::FREE => 'Livre',
        };
    }

    /**
     * Teto técnico da categoria, na mesma escala de `PlayerLevel::rank()`.
     *
     * `null` = a categoria não impõe teto, então nenhuma inscrição pode ser
     * incompatível com ela. É o caso de `FREE` (livre, por definição) e de
     * `A_PLUS_B`, que existe justamente para receber duas faixas ao mesmo tempo —
     * revisar nível num evento assim contrariaria o propósito da categoria.
     */
    public function ceilingRank(): ?int
    {
        return match ($this) {
            self::BEGINNER => 0,
            self::INTERMEDIATE => 1,
            self::ADVANCED => 2,
            self::OPEN => 3,
            self::A_PLUS_B, self::FREE => null,
        };
    }
}
