<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Enums;

/**
 * Faixa etária do evento (BRIEF §18).
 *
 * Não existe em nenhuma tela do baseline (docs/DIVERGENCES.md §3). Está aqui
 * porque o BRIEF exige a dimensão e o padrão é ADULT — nenhum formulário a
 * coleta na Fase 1, e nenhuma tela a exibe. Se continuar sem consumidor até o
 * fim da Fase 1, é candidata a remoção, não a uma tela nova.
 */
enum AgeCategory: string
{
    case ADULT = 'ADULT';
    case TEEN = 'TEEN';
    case CHILD = 'CHILD';

    public function label(): string
    {
        return match ($this) {
            self::ADULT => 'Adulto',
            self::TEEN => 'Sub-18',
            self::CHILD => 'Infantil',
        };
    }
}
