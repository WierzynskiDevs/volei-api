<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Domain;

use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Registrations\Domain\Enums\LevelReview;
use App\Modules\Users\Domain\Enums\PlayerLevel;

/**
 * Decide se uma inscrição precisa de análise de nível (aditivo §17).
 *
 * Regra pura, sem Eloquent e sem IO (CLAUDE.md §4.3), porque é ela que decide se
 * o organizador é acionado — e isso precisa de teste sem banco.
 *
 * A regra é **assimétrica de propósito**: só nível ACIMA da categoria gera
 * revisão. Um atleta iniciante entrando em evento Open não é problema de
 * competitividade — é escolha dele. O aditivo §17 fala apenas em "nível do
 * atleta superior ao nível do evento".
 */
final readonly class LevelCompatibility
{
    /**
     * Revisão exigida quando o nível do atleta passa do teto da categoria.
     *
     * Nível não declarado (`null`) **não** gera revisão: `users.level` é
     * nullable e o cadastro do baseline não o coleta
     * (docs/DIVERGENCES.md §9). Exigir análise de todo mundo que nunca
     * preencheu o perfil transformaria a fila do organizador em ruído.
     */
    public static function evaluate(?PlayerLevel $player, LevelCategory $event): LevelReview
    {
        if ($player === null) {
            return LevelReview::NOT_REQUIRED;
        }

        $ceiling = $event->ceilingRank();

        // Categoria sem teto (FREE, A+B): nada é incompatível com ela.
        if ($ceiling === null) {
            return LevelReview::NOT_REQUIRED;
        }

        return $player->rank() > $ceiling
            ? LevelReview::REQUIRED
            : LevelReview::NOT_REQUIRED;
    }

    /** Açúcar para leitura em condicional. */
    public static function requiresReview(?PlayerLevel $player, LevelCategory $event): bool
    {
        return self::evaluate($player, $event) === LevelReview::REQUIRED;
    }
}
