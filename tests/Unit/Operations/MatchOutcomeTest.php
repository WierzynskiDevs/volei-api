<?php

declare(strict_types=1);

use App\Modules\Operations\Domain\Enums\MatchSide;
use App\Modules\Operations\Domain\MatchOutcome;

/*
 * Cálculo de vencedor da partida (ADR 0013 §4, S9). CLAUDE.md §22: unit,
 * sem banco — o placar por set já é o resultado que o juiz digitou.
 */

describe('melhor de 3', function (): void {
    it('sem sets registrados, ninguém venceu', function (): void {
        expect(MatchOutcome::winner([], 3))->toBeNull();
    });

    it('1 set para cada lado, ainda não decidido', function (): void {
        $sets = [['score_a' => 21, 'score_b' => 18], ['score_a' => 15, 'score_b' => 21]];
        expect(MatchOutcome::winner($sets, 3))->toBeNull();
    });

    it('2 sets para A decide, mesmo sem jogar o 3º', function (): void {
        $sets = [['score_a' => 21, 'score_b' => 18], ['score_a' => 21, 'score_b' => 15]];
        expect(MatchOutcome::winner($sets, 3))->toBe(MatchSide::A);
    });

    it('2 sets para B decide', function (): void {
        $sets = [['score_a' => 18, 'score_b' => 21], ['score_a' => 15, 'score_b' => 21]];
        expect(MatchOutcome::winner($sets, 3))->toBe(MatchSide::B);
    });

    it('vai a 3 sets — quem vence o terceiro leva a partida', function (): void {
        $sets = [
            ['score_a' => 21, 'score_b' => 18],
            ['score_a' => 15, 'score_b' => 21],
            ['score_a' => 12, 'score_b' => 15],
        ];
        expect(MatchOutcome::winner($sets, 3))->toBe(MatchSide::B);
    });
});

describe('melhor de 1 — set único decide', function (): void {
    it('vencedor do set é o vencedor da partida', function (): void {
        expect(MatchOutcome::winner([['score_a' => 21, 'score_b' => 19]], 1))->toBe(MatchSide::A);
    });
});

describe('melhor de 5', function (): void {
    it('precisa de 3 sets, 2 não decide', function (): void {
        $sets = [
            ['score_a' => 21, 'score_b' => 10],
            ['score_a' => 21, 'score_b' => 10],
        ];
        expect(MatchOutcome::winner($sets, 5))->toBeNull();
    });

    it('3 sets para o mesmo lado decide (precisa de 3 em 5)', function (): void {
        $sets = [
            ['score_a' => 21, 'score_b' => 10],
            ['score_a' => 10, 'score_b' => 21],
            ['score_a' => 21, 'score_b' => 10],
            ['score_a' => 21, 'score_b' => 10],
        ];
        expect(MatchOutcome::winner($sets, 5))->toBe(MatchSide::A);
    });
});

describe('empate no set', function (): void {
    it('não conta para nenhum lado — dado inconsistente não vira decisão', function (): void {
        $sets = [['score_a' => 21, 'score_b' => 21]];
        expect(MatchOutcome::winner($sets, 1))->toBeNull();
    });
});

describe('isDecided', function (): void {
    it('espelha winner() !== null', function (): void {
        expect(MatchOutcome::isDecided([], 3))->toBeFalse();

        $sets = [['score_a' => 21, 'score_b' => 10], ['score_a' => 21, 'score_b' => 10]];
        expect(MatchOutcome::isDecided($sets, 3))->toBeTrue();
    });
});
