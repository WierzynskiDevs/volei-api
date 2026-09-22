<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Enums;

/**
 * Modalidade (BRIEF §18, docs/DIVERGENCES.md §3).
 *
 * No baseline é string livre — `"2x2"`, `"2x2 rotativo"`, `"4x4"`. Conjunto
 * fechado vira enum (CLAUDE.md §5); `TWO_VS_TWO_ROTATING` existe porque o
 * evento "Americano de Verão" já exibe "2x2 rotativo" e sumiria da tela sem ele.
 */
enum Modality: string
{
    case TWO_VS_TWO = 'TWO_VS_TWO';
    case TWO_VS_TWO_ROTATING = 'TWO_VS_TWO_ROTATING';
    case FOUR_VS_FOUR = 'FOUR_VS_FOUR';

    public function label(): string
    {
        return match ($this) {
            self::TWO_VS_TWO => '2x2',
            self::TWO_VS_TWO_ROTATING => '2x2 rotativo',
            self::FOUR_VS_FOUR => '4x4',
        };
    }

    /** Quantos atletas formam uma equipe — usado para converter duplas em vagas. */
    public function playersPerTeam(): int
    {
        return match ($this) {
            self::TWO_VS_TWO, self::TWO_VS_TWO_ROTATING => 2,
            self::FOUR_VS_FOUR => 4,
        };
    }
}
