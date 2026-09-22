<?php

declare(strict_types=1);

use App\Modules\Operations\Domain\Enums\MatchStatus;

/*
 * Máquina de estados da partida (ADR 0013 §3, S9). CLAUDE.md §22: unit, sem
 * banco.
 */

describe('transições válidas', function (): void {
    it('atribui quadra a uma partida pendente', function (): void {
        expect(MatchStatus::PENDENTE->canTransitionTo(MatchStatus::ATRIBUIDA))->toBeTrue();
    });

    it('fica pronta depois de atribuída', function (): void {
        expect(MatchStatus::ATRIBUIDA->canTransitionTo(MatchStatus::PRONTA))->toBeTrue();
    });

    it('inicia a partir de pronta', function (): void {
        expect(MatchStatus::PRONTA->canTransitionTo(MatchStatus::EM_ANDAMENTO))->toBeTrue();
    });

    it('finaliza a partir de em andamento', function (): void {
        expect(MatchStatus::EM_ANDAMENTO->canTransitionTo(MatchStatus::FINALIZADA))->toBeTrue();
    });

    it('cancela de qualquer estado não terminal', function (MatchStatus $from): void {
        expect($from->canTransitionTo(MatchStatus::CANCELADA))->toBeTrue();
    })->with([MatchStatus::PENDENTE, MatchStatus::ATRIBUIDA, MatchStatus::PRONTA]);
});

describe('transições inválidas', function (): void {
    it('não pula de pendente direto para em andamento', function (): void {
        expect(MatchStatus::PENDENTE->canTransitionTo(MatchStatus::EM_ANDAMENTO))->toBeFalse();
    });

    it('não sai de finalizada nem de cancelada', function (): void {
        expect(MatchStatus::FINALIZADA->allowedTransitions())->toBe([])
            ->and(MatchStatus::CANCELADA->allowedTransitions())->toBe([]);
    });

    it('não cancela partida já em andamento (fluxo desta fatia)', function (): void {
        expect(MatchStatus::EM_ANDAMENTO->canTransitionTo(MatchStatus::CANCELADA))->toBeFalse();
    });
});

describe('fromAssignment', function (): void {
    it('sem quadra nem juiz, fica pendente', function (): void {
        expect(MatchStatus::fromAssignment(null, null))->toBe(MatchStatus::PENDENTE);
    });

    it('só com quadra, fica atribuída', function (): void {
        expect(MatchStatus::fromAssignment('court-1', null))->toBe(MatchStatus::ATRIBUIDA);
    });

    it('com quadra e juiz, fica pronta', function (): void {
        expect(MatchStatus::fromAssignment('court-1', 'ref-1'))->toBe(MatchStatus::PRONTA);
    });

    it('só com juiz e sem quadra, ainda é pendente — quadra manda', function (): void {
        expect(MatchStatus::fromAssignment(null, 'ref-1'))->toBe(MatchStatus::PENDENTE);
    });
});

it('todo estado tem rótulo em pt-BR', function (): void {
    foreach (MatchStatus::cases() as $status) {
        expect($status->label())->not->toBeEmpty();
    }
});
