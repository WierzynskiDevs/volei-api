<?php

declare(strict_types=1);

use App\Modules\Events\Domain\Enums\EventStatus;

/*
 * Máquina de estados do evento (CLAUDE.md §22: unit, sem banco).
 *
 * A regra que estes testes protegem é a de docs/DIVERGENCES.md §4, ADR 0011
 * (sorteio inicial) e ADR 0013 (S9 — partidas/sets): a Fase 1 implementa os
 * nove estados que o baseline já renderiza. Nenhum é mais "reservado" — o
 * último a sair da reserva foi IN_PROGRESS, nesta fatia.
 */

describe('transições válidas', function () {
    it('publica um rascunho', function () {
        expect(EventStatus::DRAFT->canTransitionTo(EventStatus::PUBLISHED))->toBeTrue();
    });

    it('abre e encerra inscrições', function () {
        expect(EventStatus::PUBLISHED->canTransitionTo(EventStatus::REGISTRATION_OPEN))->toBeTrue()
            ->and(EventStatus::REGISTRATION_OPEN->canTransitionTo(EventStatus::REGISTRATION_CLOSED))->toBeTrue();
    });

    it('reabre inscrições encerradas', function () {
        expect(EventStatus::REGISTRATION_CLOSED->canTransitionTo(EventStatus::REGISTRATION_OPEN))->toBeTrue();
    });

    it('permite cancelar de qualquer estado não terminal', function (EventStatus $from) {
        expect($from->canTransitionTo(EventStatus::CANCELLED))->toBeTrue();
    })->with([
        EventStatus::DRAFT,
        EventStatus::PUBLISHED,
        EventStatus::REGISTRATION_OPEN,
        EventStatus::REGISTRATION_CLOSED,
        EventStatus::AWAITING_DRAW,
        EventStatus::BRACKET_PUBLISHED,
    ]);

    it('percorre o sorteio inicial (ADR 0011)', function () {
        expect(EventStatus::REGISTRATION_CLOSED->canTransitionTo(EventStatus::AWAITING_DRAW))->toBeTrue()
            ->and(EventStatus::AWAITING_DRAW->canTransitionTo(EventStatus::BRACKET_PUBLISHED))->toBeTrue();
    });

    it('entra em andamento a partir de REGISTRATION_CLOSED (evento sem sorteio) ou BRACKET_PUBLISHED (com sorteio) — ADR 0013', function () {
        expect(EventStatus::REGISTRATION_CLOSED->canTransitionTo(EventStatus::IN_PROGRESS))->toBeTrue()
            ->and(EventStatus::BRACKET_PUBLISHED->canTransitionTo(EventStatus::IN_PROGRESS))->toBeTrue();
    });

    it('encerra a partir de em andamento', function () {
        expect(EventStatus::IN_PROGRESS->canTransitionTo(EventStatus::FINISHED))->toBeTrue();
    });
});

describe('transições inválidas', function () {
    it('não pula do rascunho direto para inscrições abertas', function () {
        expect(EventStatus::DRAFT->canTransitionTo(EventStatus::REGISTRATION_OPEN))->toBeFalse();
    });

    it('não ressuscita evento cancelado', function () {
        expect(EventStatus::CANCELLED->allowedTransitions())->toBe([])
            ->and(EventStatus::CANCELLED->canTransitionTo(EventStatus::PUBLISHED))->toBeFalse();
    });

    it('não reabre evento finalizado', function () {
        expect(EventStatus::FINISHED->allowedTransitions())->toBe([]);
    });

    it('não republica um evento já publicado', function () {
        expect(EventStatus::PUBLISHED->canTransitionTo(EventStatus::PUBLISHED))->toBeFalse();
    });
});

describe('IN_PROGRESS (ADR 0013 — S9)', function () {
    it('só é alcançável a partir de REGISTRATION_CLOSED ou BRACKET_PUBLISHED', function () {
        $reachedFrom = [];

        foreach (EventStatus::cases() as $status) {
            if (in_array(EventStatus::IN_PROGRESS, $status->allowedTransitions(), strict: true)) {
                $reachedFrom[] = $status;
            }
        }

        expect($reachedFrom)->toEqualCanonicalizing([
            EventStatus::REGISTRATION_CLOSED,
            EventStatus::BRACKET_PUBLISHED,
        ]);
    });

    it('não sai de IN_PROGRESS por reabertura de inscrição nem sorteio', function () {
        expect(EventStatus::IN_PROGRESS->canTransitionTo(EventStatus::REGISTRATION_OPEN))->toBeFalse()
            ->and(EventStatus::IN_PROGRESS->canTransitionTo(EventStatus::AWAITING_DRAW))->toBeFalse();
    });

    it('continua exibível: tem rótulo e é publicamente visível', function () {
        expect(EventStatus::IN_PROGRESS->label())->not->toBeEmpty()
            ->and(EventStatus::IN_PROGRESS->isPubliclyVisible())->toBeTrue();
    });
});

describe('visibilidade', function () {
    it('esconde apenas o rascunho do público', function () {
        expect(EventStatus::DRAFT->isPubliclyVisible())->toBeFalse()
            ->and(EventStatus::CANCELLED->isPubliclyVisible())->toBeTrue();
    });

    it('tira cancelado da vitrine, sem tirar a página', function () {
        expect(EventStatus::CANCELLED->isListedPublicly())->toBeFalse()
            ->and(EventStatus::CANCELLED->isPubliclyVisible())->toBeTrue()
            ->and(EventStatus::REGISTRATION_OPEN->isListedPublicly())->toBeTrue();
    });

    it('mantém o evento finalizado na vitrine, como no baseline', function () {
        // `circuito-litoral-etapa-3` aparece em /eventos com o selo "Finalizado".
        expect(EventStatus::FINISHED->isListedPublicly())->toBeTrue();
    });

    it('só aceita inscrição com inscrições abertas', function () {
        expect(EventStatus::REGISTRATION_OPEN->acceptsRegistrations())->toBeTrue()
            ->and(EventStatus::PUBLISHED->acceptsRegistrations())->toBeFalse();
    });
});

it('todo estado tem rótulo em pt-BR', function () {
    foreach (EventStatus::cases() as $status) {
        expect($status->label())->not->toBeEmpty();
    }
});
