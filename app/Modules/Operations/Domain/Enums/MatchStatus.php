<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Enums;

/**
 * Máquina de estados da partida (ADR 0013 §3, S9).
 *
 * Nomes em português — espelham `OpsStatus` do baseline (`operations.tsx`).
 * Escopo desta fatia: `ADIADA` existe no enum (mesmo catálogo do mock), mas
 * nenhuma action ainda transiciona para lá — reservado, mesmo tratamento que
 * `EventStatus::IN_PROGRESS` recebeu antes do S9 existir.
 */
enum MatchStatus: string
{
    case PENDENTE = 'PENDENTE';
    case ATRIBUIDA = 'ATRIBUIDA';
    case PRONTA = 'PRONTA';
    case EM_ANDAMENTO = 'EM_ANDAMENTO';
    case FINALIZADA = 'FINALIZADA';
    case CANCELADA = 'CANCELADA';
    /** Reservado — sem transição implementada nesta fatia. */
    case ADIADA = 'ADIADA';

    public function label(): string
    {
        return match ($this) {
            self::PENDENTE => 'Pendente',
            self::ATRIBUIDA => 'Atribuída',
            self::PRONTA => 'Pronta',
            self::EM_ANDAMENTO => 'Em andamento',
            self::FINALIZADA => 'Finalizada',
            self::CANCELADA => 'Cancelada',
            self::ADIADA => 'Adiada',
        };
    }

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDENTE => [self::ATRIBUIDA, self::CANCELADA],
            self::ATRIBUIDA => [self::PRONTA, self::PENDENTE, self::CANCELADA],
            self::PRONTA => [self::ATRIBUIDA, self::EM_ANDAMENTO, self::CANCELADA],
            self::EM_ANDAMENTO => [self::FINALIZADA],
            self::FINALIZADA, self::CANCELADA, self::ADIADA => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    public function isTerminal(): bool
    {
        return $this === self::FINALIZADA || $this === self::CANCELADA;
    }

    /**
     * Deriva PENDENTE/ATRIBUIDA/PRONTA a partir de quadra e juiz atribuídos
     * (ADR 0013 §3). Só chamado por quem já garantiu que o status atual está
     * no cluster "ainda não começou" — nunca sobrescreve EM_ANDAMENTO,
     * FINALIZADA, CANCELADA ou ADIADA.
     */
    public static function fromAssignment(?string $courtId, ?string $refereeId): self
    {
        if ($courtId !== null && $refereeId !== null) {
            return self::PRONTA;
        }

        if ($courtId !== null) {
            return self::ATRIBUIDA;
        }

        return self::PENDENTE;
    }
}
