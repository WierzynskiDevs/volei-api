<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

use App\Modules\Operations\Domain\Enums\MatchSide;

/**
 * Determina o vencedor de uma partida a partir dos sets já registrados
 * (ADR 0013 §4, S9). Puro — sem banco, sem IO (CLAUDE.md §4.3), testável
 * isoladamente (§22).
 *
 * Quem venceu o SET é decidido por quem fez mais pontos naquele set — o
 * placar já é o resultado final que o juiz digitou, não recalculado a
 * partir de `points_per_set`/`tiebreak_points`. Esses dois campos orientam
 * o juiz durante o jogo; não são recomputados aqui.
 */
final readonly class MatchOutcome
{
    /**
     * @param  list<array{score_a: int, score_b: int}>  $sets
     */
    public static function winner(array $sets, int $bestOfSets): ?MatchSide
    {
        $needed = intdiv($bestOfSets, 2) + 1;
        [$winsA, $winsB] = self::tally($sets);

        if ($winsA >= $needed) {
            return MatchSide::A;
        }

        if ($winsB >= $needed) {
            return MatchSide::B;
        }

        return null;
    }

    /**
     * @param  list<array{score_a: int, score_b: int}>  $sets
     */
    public static function isDecided(array $sets, int $bestOfSets): bool
    {
        return self::winner($sets, $bestOfSets) !== null;
    }

    /**
     * @param  list<array{score_a: int, score_b: int}>  $sets
     * @return array{0: int, 1: int}
     */
    private static function tally(array $sets): array
    {
        $winsA = 0;
        $winsB = 0;

        foreach ($sets as $set) {
            if ($set['score_a'] > $set['score_b']) {
                $winsA++;
            } elseif ($set['score_b'] > $set['score_a']) {
                $winsB++;
            }
            // Empate no set não conta para ninguém — dado inconsistente que
            // a validação de forma já deveria ter barrado, mas o cálculo não
            // finge decidir o que o placar não decidiu.
        }

        return [$winsA, $winsB];
    }
}
