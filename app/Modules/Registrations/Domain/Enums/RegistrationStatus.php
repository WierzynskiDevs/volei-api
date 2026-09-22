<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Domain\Enums;

/**
 * Ciclo de vida da inscrição de UM atleta (ADR 0001: a inscrição é por jogador).
 *
 * Este enum trata só do ciclo de vida. A análise de nível é uma dimensão
 * **paralela** e vive em `LevelReview` — o aditivo §17 é explícito em que nível
 * incompatível "NÃO bloqueia automaticamente", e a própria tela
 * `/inscricao/{slug}` diz "a inscrição segue normalmente". Misturar as duas
 * coisas num único enum obrigaria a escolher entre travar a inscrição e perder
 * a informação da revisão.
 *
 * A vaga é ocupada na confirmação do pagamento, com reserva temporária durante
 * o checkout (ADR 0003) — ver `occupiesSlot()`.
 */
enum RegistrationStatus: string
{
    /** Parceiro convidado que ainda não aceitou (ADR 0001, modo "parceiro"). */
    case PENDING_ACCEPTANCE = 'PENDING_ACCEPTANCE';

    /** Inscrição criada, cobrança em aberto. Reserva a vaga até `reserved_until`. */
    case PENDING_PAYMENT = 'PENDING_PAYMENT';

    /** Pagamento confirmado pelo gateway. A vaga é definitivamente dela. */
    case CONFIRMED = 'CONFIRMED';

    /** Cancelada por atleta, organizador ou admin. Estado terminal. */
    case CANCELLED = 'CANCELLED';

    /**
     * Reserva vencida sem pagamento. Estado terminal e auditável — ADR 0003 é
     * explícito: reserva expirada NÃO apaga a inscrição, para preservar trilha.
     */
    case EXPIRED = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_ACCEPTANCE => 'Aguardando aceite do parceiro',
            self::PENDING_PAYMENT => 'Aguardando pagamento',
            self::CONFIRMED => 'Confirmada',
            self::CANCELLED => 'Cancelada',
            self::EXPIRED => 'Expirada',
        };
    }

    /**
     * Ocupa vaga no evento?
     *
     * `PENDING_ACCEPTANCE` não ocupa: quem sustenta a vaga da dupla é a
     * inscrição do capitão, que já está em `PENDING_PAYMENT` ou `CONFIRMED`.
     * Contar as duas ocuparia duas vagas para uma dupla.
     *
     * Para `PENDING_PAYMENT` a ocupação ainda depende de `reserved_until` não
     * ter vencido — quem cruza as duas coisas é `EventOccupancy`.
     */
    public function occupiesSlot(): bool
    {
        return match ($this) {
            self::PENDING_PAYMENT, self::CONFIRMED => true,
            self::PENDING_ACCEPTANCE, self::CANCELLED, self::EXPIRED => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::CANCELLED, self::EXPIRED => true,
            default => false,
        };
    }

    /**
     * Conta para a trava de duplicidade `(event_id, user_id)`?
     *
     * Estado terminal não conta: quem teve a reserva expirada ou cancelou tem
     * de poder se inscrever de novo enquanto houver vaga. É por isso que o
     * unique no banco é **parcial**.
     */
    public function blocksNewRegistration(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Transições válidas (CLAUDE.md §8: máquina de estado explícita).
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            /*
             * `CONFIRMED` direto existe para o mesmo motivo que
             * `CreateRegistrationAction::initialStatusFor()` manda o capitão
             * direto para `CONFIRMED` em evento gratuito: sem cobrança, não
             * faz sentido passar por `PENDING_PAYMENT` (Q14, ADR 0015).
             */
            self::PENDING_ACCEPTANCE => [self::PENDING_PAYMENT, self::CONFIRMED, self::CANCELLED, self::EXPIRED],
            self::PENDING_PAYMENT => [self::CONFIRMED, self::CANCELLED, self::EXPIRED],
            // Confirmada só sai por cancelamento — que é o caminho do reembolso.
            self::CONFIRMED => [self::CANCELLED],
            self::CANCELLED, self::EXPIRED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /**
     * Valores que ocupam vaga, para uso em cláusula SQL.
     *
     * Derivado do enum em vez de repetido na query: duas listas divergem no dia
     * em que um estado novo entra.
     *
     * @return array<int, string>
     */
    public static function occupyingValues(): array
    {
        return array_values(array_map(
            fn (self $s): string => $s->value,
            array_filter(self::cases(), fn (self $s): bool => $s->occupiesSlot()),
        ));
    }

    /** @return array<int, string> */
    public static function blockingValues(): array
    {
        return array_values(array_map(
            fn (self $s): string => $s->value,
            array_filter(self::cases(), fn (self $s): bool => $s->blocksNewRegistration()),
        ));
    }
}
