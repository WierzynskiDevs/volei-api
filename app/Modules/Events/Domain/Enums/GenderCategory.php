<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Enums;

/**
 * Categoria de gênero do evento (BRIEF §18, docs/DIVERGENCES.md §3).
 *
 * O baseline usa um único campo `Category` que MISTURA gênero e nível:
 * `"Masculino" | "Feminino" | "Misto" | "Open"` — e `"Open"` também aparece
 * em `SkillLevel`. O backend separa as duas dimensões, como o BRIEF define,
 * e mantém OPEN aqui para que nenhuma tela perca o valor que já exibe
 * (OPEN-QUESTIONS Q4).
 *
 * O rótulo em pt-BR continua sendo formatação do frontend (CLAUDE.md §15).
 */
enum GenderCategory: string
{
    case MALE = 'MALE';
    case FEMALE = 'FEMALE';
    case MIXED = 'MIXED';
    case OPEN = 'OPEN';

    public function label(): string
    {
        return match ($this) {
            self::MALE => 'Masculino',
            self::FEMALE => 'Feminino',
            self::MIXED => 'Misto',
            self::OPEN => 'Open',
        };
    }
}
