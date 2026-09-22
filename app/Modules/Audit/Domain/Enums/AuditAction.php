<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Enums;

/**
 * Ações auditáveis (BRIEF §41).
 *
 * Estes valores são persistidos e consultados pela tela /admin/auditoria.
 * Renomear um valor quebra o histórico — só se acrescenta.
 */
enum AuditAction: string
{
    // Usuários
    case USER_CREATED = 'USER_CREATED';
    case USER_UPDATED = 'USER_UPDATED';
    case USER_BLOCKED = 'USER_BLOCKED';
    case USER_UNBLOCKED = 'USER_UNBLOCKED';
    case USER_LOGGED_IN = 'USER_LOGGED_IN';
    case USER_LOGGED_OUT = 'USER_LOGGED_OUT';
    case USER_PASSWORD_RESET_REQUESTED = 'USER_PASSWORD_RESET_REQUESTED';
    case USER_PASSWORD_RESET = 'USER_PASSWORD_RESET';

    // Organizadores
    case ORGANIZER_CREATED = 'ORGANIZER_CREATED';
    case ORGANIZER_UPDATED = 'ORGANIZER_UPDATED';
    case ORGANIZER_BLOCKED = 'ORGANIZER_BLOCKED';
    /*
     * Situação do organizador mudou para algo que NÃO é bloqueio — REGULAR
     * (liberado) ou ATTENTION (advertido, botão "Advertir" da tela
     * `/admin/organizadores`). Separado de `ORGANIZER_BLOCKED` de propósito:
     * a auditoria de bloqueio exige justificativa (ver `requiresJustification`)
     * e é o registro que interessa numa investigação. Fundir os dois obrigaria
     * a ler o metadata para saber se a conta foi travada ou liberada.
     */
    case ORGANIZER_STATUS_CHANGED = 'ORGANIZER_STATUS_CHANGED';
    case ORGANIZER_PLAN_CHANGED = 'ORGANIZER_PLAN_CHANGED';

    // Eventos
    case EVENT_CREATED = 'EVENT_CREATED';
    case EVENT_UPDATED = 'EVENT_UPDATED';
    case EVENT_PUBLISHED = 'EVENT_PUBLISHED';
    case EVENT_REGISTRATIONS_OPENED = 'EVENT_REGISTRATIONS_OPENED';
    case EVENT_REGISTRATIONS_CLOSED = 'EVENT_REGISTRATIONS_CLOSED';
    case EVENT_CANCELLED = 'EVENT_CANCELLED';
    /** Sorteio inicial (ADR 0011): entrada e publicação da chave. */
    case EVENT_DRAW_STARTED = 'EVENT_DRAW_STARTED';
    case EVENT_BRACKET_PUBLISHED = 'EVENT_BRACKET_PUBLISHED';
    /** IN_PROGRESS automático na primeira partida iniciada (ADR 0013 §3, S9). */
    case EVENT_STARTED = 'EVENT_STARTED';
    /** Quadras redefinidas (ADR 0013, S8a) — distinto de EVENT_UPDATED por ter payload próprio. */
    case EVENT_COURTS_UPDATED = 'EVENT_COURTS_UPDATED';

    // Juízes (ADR 0013 §5/§6/§8, S8b)
    case REFEREE_ADDED = 'REFEREE_ADDED';
    case REFEREE_INVITED = 'REFEREE_INVITED';
    case REFEREE_COURT_ASSIGNED = 'REFEREE_COURT_ASSIGNED';
    case REFEREE_INVITATION_ACCEPTED = 'REFEREE_INVITATION_ACCEPTED';

    // Partidas (ADR 0013 §3/§4, S9)
    case MATCH_CREATED = 'MATCH_CREATED';
    case MATCH_COURT_ASSIGNED = 'MATCH_COURT_ASSIGNED';
    case MATCH_REFEREE_ASSIGNED = 'MATCH_REFEREE_ASSIGNED';
    case MATCH_STARTED = 'MATCH_STARTED';
    case MATCH_SET_RECORDED = 'MATCH_SET_RECORDED';
    /** Correção de set já gravado — nunca some o valor anterior, só audita a mudança. */
    case MATCH_SET_CORRECTED = 'MATCH_SET_CORRECTED';
    case MATCH_FINISHED = 'MATCH_FINISHED';
    case MATCH_CANCELLED = 'MATCH_CANCELLED';

    // Inscrições
    case REGISTRATION_CREATED = 'REGISTRATION_CREATED';
    /**
     * Convite de dupla aceito pelo parceiro (Q14, ADR 0015). Distinto de
     * `REGISTRATION_CONFIRMED`: aceitar é o parceiro concordando em entrar na
     * dupla, disparado por ele mesmo; confirmar é o gateway avisando que o
     * pagamento caiu. As duas podem acontecer em momentos bem separados.
     */
    case REGISTRATION_ACCEPTED = 'REGISTRATION_ACCEPTED';
    case REGISTRATION_CONFIRMED = 'REGISTRATION_CONFIRMED';
    case REGISTRATION_CANCELLED = 'REGISTRATION_CANCELLED';

    // Pagamentos
    case PAYMENT_CREATED = 'PAYMENT_CREATED';
    case PAYMENT_CONFIRMED = 'PAYMENT_CONFIRMED';
    case PAYMENT_RECEIVED = 'PAYMENT_RECEIVED';
    case PAYMENT_FAILED = 'PAYMENT_FAILED';
    case REFUND_CREATED = 'REFUND_CREATED';
    case REFUND_COMPLETED = 'REFUND_COMPLETED';

    // Administração
    case ADMIN_ACTION = 'ADMIN_ACTION';

    /**
     * Ações que exigem justificativa registrada.
     * Ver BRIEF §35/§36: alteração e cancelamento de evento sem justificativa
     * não pode acontecer, porque disparam direito a reembolso.
     */
    public function requiresJustification(): bool
    {
        return match ($this) {
            self::EVENT_CANCELLED,
            self::USER_BLOCKED,
            self::ORGANIZER_BLOCKED => true,
            default => false,
        };
    }
}
