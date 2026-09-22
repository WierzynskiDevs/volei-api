<?php

declare(strict_types=1);

use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Registrations\Domain\Enums\LevelReview;
use App\Modules\Registrations\Domain\LevelCompatibility;
use App\Modules\Users\Domain\Enums\PlayerLevel;

/*
 * Regra do aditivo §17: nível ACIMA da categoria gera análise, e nada é
 * bloqueado automaticamente. Teste puro, sem banco (CLAUDE.md §22).
 */

describe('LevelCompatibility', function (): void {

    it('exige análise quando o atleta está acima da categoria', function (
        PlayerLevel $player,
        LevelCategory $event,
    ): void {
        expect(LevelCompatibility::evaluate($player, $event))->toBe(LevelReview::REQUIRED);
    })->with([
        'open em intermediário' => [PlayerLevel::OPEN, LevelCategory::INTERMEDIATE],
        'open em iniciante' => [PlayerLevel::OPEN, LevelCategory::BEGINNER],
        'open em avançado' => [PlayerLevel::OPEN, LevelCategory::ADVANCED],
        'avançado em intermediário' => [PlayerLevel::ADVANCED, LevelCategory::INTERMEDIATE],
        'avançado em iniciante' => [PlayerLevel::ADVANCED, LevelCategory::BEGINNER],
        'intermediário em iniciante' => [PlayerLevel::INTERMEDIATE, LevelCategory::BEGINNER],
    ]);

    it('não exige análise quando o nível cabe na categoria', function (
        PlayerLevel $player,
        LevelCategory $event,
    ): void {
        expect(LevelCompatibility::evaluate($player, $event))->toBe(LevelReview::NOT_REQUIRED);
    })->with([
        'mesmo nível' => [PlayerLevel::INTERMEDIATE, LevelCategory::INTERMEDIATE],
        'open é o teto' => [PlayerLevel::OPEN, LevelCategory::OPEN],
        'iniciante em iniciante' => [PlayerLevel::BEGINNER, LevelCategory::BEGINNER],
    ]);

    /*
     * A regra é assimétrica de propósito: o aditivo §17 fala só em nível
     * "superior". Um iniciante entrando em evento Open é escolha dele, não
     * problema de competitividade — e mandar isso para a fila do organizador
     * só geraria ruído.
     */
    it('nunca exige análise para quem está ABAIXO da categoria', function (
        PlayerLevel $player,
        LevelCategory $event,
    ): void {
        expect(LevelCompatibility::requiresReview($player, $event))->toBeFalse();
    })->with([
        'iniciante em open' => [PlayerLevel::BEGINNER, LevelCategory::OPEN],
        'iniciante em avançado' => [PlayerLevel::BEGINNER, LevelCategory::ADVANCED],
        'intermediário em open' => [PlayerLevel::INTERMEDIATE, LevelCategory::OPEN],
    ]);

    /*
     * Categoria sem teto. `A_PLUS_B` existe justamente para receber duas faixas
     * ao mesmo tempo (ADR/Q11) — revisar nível nela contrariaria o propósito.
     */
    it('não exige análise em categoria sem teto, qualquer que seja o nível', function (
        LevelCategory $event,
    ): void {
        foreach (PlayerLevel::cases() as $player) {
            expect(LevelCompatibility::evaluate($player, $event))
                ->toBe(LevelReview::NOT_REQUIRED, "nível {$player->value} em {$event->value}");
        }
    })->with([
        'livre' => [LevelCategory::FREE],
        'A+B' => [LevelCategory::A_PLUS_B],
    ]);

    /*
     * `users.level` é nullable e o cadastro do baseline não o coleta
     * (docs/DIVERGENCES.md §9). Exigir análise de todo mundo sem perfil
     * preenchido transformaria a fila do organizador em ruído.
     */
    it('não exige análise quando o atleta não declarou nível', function (): void {
        foreach (LevelCategory::cases() as $event) {
            expect(LevelCompatibility::evaluate(null, $event))
                ->toBe(LevelReview::NOT_REQUIRED, "categoria {$event->value}");
        }
    });

    it('cobre todas as categorias: nenhuma fica sem teto definido por esquecimento', function (): void {
        $withoutCeiling = array_values(array_filter(
            LevelCategory::cases(),
            fn (LevelCategory $c): bool => $c->ceilingRank() === null,
        ));

        // Se um valor novo entrar no enum sem decisão sobre teto, este teste
        // falha e força a decisão em vez de deixar o default silencioso.
        expect($withoutCeiling)->toBe([LevelCategory::A_PLUS_B, LevelCategory::FREE]);
    });
});
