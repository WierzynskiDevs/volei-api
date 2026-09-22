<?php

declare(strict_types=1);

use App\Modules\Registrations\Domain\Enums\RegistrationStatus as Status;

/*
 * Máquina de estados da inscrição (CLAUDE.md §8). Sem banco.
 */

describe('RegistrationStatus — ocupação de vaga', function (): void {

    it('ocupa vaga apenas em pagamento pendente e confirmada', function (): void {
        expect(Status::PENDING_PAYMENT->occupiesSlot())->toBeTrue()
            ->and(Status::CONFIRMED->occupiesSlot())->toBeTrue();
    });

    /*
     * ADR 0001: a dupla nasce com duas inscrições, mas consome UMA vaga. Quem
     * sustenta a vaga é o capitão; se o parceiro pendente também ocupasse, uma
     * dupla comeria duas vagas do evento.
     */
    it('não ocupa vaga enquanto o parceiro não aceita', function (): void {
        expect(Status::PENDING_ACCEPTANCE->occupiesSlot())->toBeFalse();
    });

    it('não ocupa vaga em estado terminal', function (): void {
        expect(Status::CANCELLED->occupiesSlot())->toBeFalse()
            ->and(Status::EXPIRED->occupiesSlot())->toBeFalse();
    });

    it('deriva a lista de estados que ocupam vaga do próprio enum', function (): void {
        expect(Status::occupyingValues())->toBe(['PENDING_PAYMENT', 'CONFIRMED']);
    });
});

describe('RegistrationStatus — duplicidade', function (): void {

    /*
     * ADR 0003: reserva expirada libera a vaga. Quem expirou tem de poder se
     * inscrever de novo — por isso o unique no banco é parcial.
     */
    it('estado terminal não bloqueia nova inscrição', function (): void {
        expect(Status::CANCELLED->blocksNewRegistration())->toBeFalse()
            ->and(Status::EXPIRED->blocksNewRegistration())->toBeFalse();
    });

    it('estado ativo bloqueia nova inscrição', function (): void {
        expect(Status::PENDING_ACCEPTANCE->blocksNewRegistration())->toBeTrue()
            ->and(Status::PENDING_PAYMENT->blocksNewRegistration())->toBeTrue()
            ->and(Status::CONFIRMED->blocksNewRegistration())->toBeTrue();
    });

    it('a lista de bloqueio bate com o predicado do enum', function (): void {
        expect(Status::blockingValues())
            ->toBe(['PENDING_ACCEPTANCE', 'PENDING_PAYMENT', 'CONFIRMED']);
    });
});

describe('RegistrationStatus — transições', function (): void {

    it('aceita o caminho feliz: pendente de pagamento vira confirmada', function (): void {
        expect(Status::PENDING_PAYMENT->canTransitionTo(Status::CONFIRMED))->toBeTrue();
    });

    it('aceita o aceite do parceiro virando pendente de pagamento', function (): void {
        expect(Status::PENDING_ACCEPTANCE->canTransitionTo(Status::PENDING_PAYMENT))->toBeTrue();
    });

    it('aceita o aceite do parceiro indo direto para confirmada em evento gratuito (ADR 0015)', function (): void {
        expect(Status::PENDING_ACCEPTANCE->canTransitionTo(Status::CONFIRMED))->toBeTrue();
    });

    it('permite cancelar inscrição confirmada — é o caminho do reembolso', function (): void {
        expect(Status::CONFIRMED->canTransitionTo(Status::CANCELLED))->toBeTrue();
    });

    /*
     * Confirmar já expirou é o caso perigoso: significaria dar vaga a quem
     * perdeu a reserva, possivelmente por cima de quem entrou depois.
     */
    it('recusa confirmar inscrição expirada ou cancelada', function (): void {
        expect(Status::EXPIRED->canTransitionTo(Status::CONFIRMED))->toBeFalse()
            ->and(Status::CANCELLED->canTransitionTo(Status::CONFIRMED))->toBeFalse();
    });

    it('não deixa confirmada voltar para pendente', function (): void {
        expect(Status::CONFIRMED->canTransitionTo(Status::PENDING_PAYMENT))->toBeFalse();
    });

    it('trata estado terminal como sem saída', function (): void {
        expect(Status::CANCELLED->allowedTransitions())->toBe([])
            ->and(Status::EXPIRED->allowedTransitions())->toBe([])
            ->and(Status::CANCELLED->isTerminal())->toBeTrue()
            ->and(Status::EXPIRED->isTerminal())->toBeTrue();
    });

    it('nunca permite transição para o próprio estado', function (): void {
        foreach (Status::cases() as $status) {
            expect($status->canTransitionTo($status))
                ->toBeFalse("{$status->value} não deveria transicionar para si mesmo");
        }
    });
});
