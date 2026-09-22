<?php

declare(strict_types=1);

use App\Modules\Brackets\Domain\BracketSizing;

/*
 * Tamanho da chave eliminatória simples (ADR 0011, CLAUDE.md §22: unit, sem
 * banco).
 */

it('arredonda para a próxima potência de 2', function (int $teams, int $expected) {
    expect(BracketSizing::slotsFor($teams))->toBe($expected);
})->with([
    [2, 2],
    [3, 4],
    [4, 4],
    [5, 8],
    [8, 8],
    [9, 16],
]);

it('recusa menos de 2 duplas', function () {
    BracketSizing::slotsFor(1);
})->throws(InvalidArgumentException::class);
