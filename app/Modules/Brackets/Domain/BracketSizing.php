<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Domain;

use InvalidArgumentException;

/**
 * Regra pura de tamanho de chave eliminatória simples (ADR 0011).
 *
 * Sem IO, sem Eloquent (CLAUDE.md §4.3) — só a conta que decide quantas
 * posições a chave tem a partir do nº de duplas aptas ao sorteio.
 */
final class BracketSizing
{
    /**
     * Próxima potência de 2 maior ou igual a `$teams`. Duplas a menos que o
     * tamanho da chave viram "bye" na publicação (`PublishBracketAction`).
     */
    public static function slotsFor(int $teams): int
    {
        if ($teams < 2) {
            throw new InvalidArgumentException('É preciso de pelo menos 2 duplas para sortear uma chave.');
        }

        $size = 2;
        while ($size < $teams) {
            $size *= 2;
        }

        return $size;
    }
}
