<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Enums;

/**
 * Máquina de estados do evento (BRIEF §19, docs/DIVERGENCES.md §4).
 *
 * O baseline renderiza NOVE status (`EVENT_STATUS_LABEL` em `mock-data.ts`).
 * Três deles pertencem ao motor de competição, que é Fase 2
 * (docs/PHASE-2.md): AWAITING_DRAW e BRACKET_PUBLISHED ganharam transição real
 * no ADR 0011 (sorteio/chaveamento inicial — abertura parcial e deliberada da
 * Fase 2). IN_PROGRESS ganhou transição real na ADR 0013 (S9): automática,
 * disparada por `StartMatchAction` na primeira partida iniciada do evento —
 * não é decisão de negócio automatizada, é o estado refletindo um fato que
 * já aconteceu (mesmo raciocínio de AWAITING_DRAW/BRACKET_PUBLISHED).
 *
 * Transição inválida lança exceção de domínio — nunca é ignorada
 * (CLAUDE.md §8).
 */
enum EventStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case REGISTRATION_OPEN = 'REGISTRATION_OPEN';
    case REGISTRATION_CLOSED = 'REGISTRATION_CLOSED';

    /** ADR 0011: aberto por `StartDrawAction`, fechado por `PublishBracketAction`. */
    case AWAITING_DRAW = 'AWAITING_DRAW';
    case BRACKET_PUBLISHED = 'BRACKET_PUBLISHED';

    /** ADR 0013 (S9): automático, disparado por `StartMatchAction` na primeira partida iniciada. */
    case IN_PROGRESS = 'IN_PROGRESS';

    case FINISHED = 'FINISHED';
    case CANCELLED = 'CANCELLED';

    /**
     * Rótulo em pt-BR, espelhando `EVENT_STATUS_LABEL` do baseline.
     *
     * O frontend continua sendo quem formata (CLAUDE.md §15) — este rótulo
     * existe para log, auditoria e mensagem de erro do backend.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::PUBLISHED => 'Publicado',
            self::REGISTRATION_OPEN => 'Inscrições abertas',
            self::REGISTRATION_CLOSED => 'Inscrições encerradas',
            self::AWAITING_DRAW => 'Aguardando sorteio',
            self::BRACKET_PUBLISHED => 'Chave publicada',
            self::IN_PROGRESS => 'Em andamento',
            self::FINISHED => 'Finalizado',
            self::CANCELLED => 'Cancelado',
        };
    }

    /**
     * Transições válidas.
     *
     * REGISTRATION_CLOSED → AWAITING_DRAW → BRACKET_PUBLISHED é o sorteio
     * inicial (ADR 0011). REGISTRATION_CLOSED → IN_PROGRESS cobre o evento
     * SEM sorteio formal (ex.: `EventType::SOCIAL`/`FRIENDLY`); BRACKET_PUBLISHED
     * → IN_PROGRESS cobre o evento COM sorteio — as duas levam ao mesmo
     * lugar porque uma partida não depende de `bracket_slots` (ADR 0013 §1).
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::PUBLISHED, self::CANCELLED],
            self::PUBLISHED => [self::REGISTRATION_OPEN, self::CANCELLED],
            self::REGISTRATION_OPEN => [self::REGISTRATION_CLOSED, self::CANCELLED],
            self::REGISTRATION_CLOSED => [
                self::REGISTRATION_OPEN,
                self::AWAITING_DRAW,
                self::IN_PROGRESS,
                self::FINISHED,
                self::CANCELLED,
            ],
            self::AWAITING_DRAW => [self::BRACKET_PUBLISHED, self::CANCELLED],
            self::BRACKET_PUBLISHED => [self::IN_PROGRESS, self::CANCELLED],
            // Entrada é automática (StartMatchAction, ADR 0013 §3); saída é
            // ação explícita do organizador — ainda sem endpoint dedicado
            // (fica para uma fatia seguinte, não é o núcleo de S9).
            self::IN_PROGRESS => [self::FINISHED, self::CANCELLED],

            self::FINISHED,
            self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /**
     * Grupos que as abas de `/admin/eventos` usam para filtrar.
     *
     * A tela filtra por conceito, não por status um a um: "Ativo" é o evento em
     * operação — publicado, com inscrições abertas ou já encerradas — e são três
     * estados diferentes na mesma aba.
     *
     * Mesma decisão de `PaymentStatus::group()`, e pelo mesmo motivo: o mapa
     * vive no domínio para que um status novo faça este `match` falhar em
     * revisão, em vez de sumir silenciosamente de todas as abas.
     *
     * @return list<self> vazio significa "sem filtro", não "nenhum resultado"
     */
    public static function group(string $group): array
    {
        return match (mb_strtolower($group)) {
            'draft' => [self::DRAFT],
            'active' => [self::PUBLISHED, self::REGISTRATION_OPEN, self::REGISTRATION_CLOSED],
            'running' => [self::AWAITING_DRAW, self::BRACKET_PUBLISHED, self::IN_PROGRESS],
            'finished' => [self::FINISHED],
            'cancelled' => [self::CANCELLED],
            default => [],
        };
    }

    /** Estado final: nenhuma transição sai daqui. */
    public function isTerminal(): bool
    {
        return $this === self::FINISHED || $this === self::CANCELLED;
    }

    /**
     * Rascunho é privado do organizador. Todo o resto tem página pública —
     * inclusive o cancelado, que precisa continuar acessível para quem tinha
     * inscrição e chega pelo link antigo.
     */
    public function isPubliclyVisible(): bool
    {
        return $this !== self::DRAFT;
    }

    /**
     * Aparece na vitrine (`/eventos`).
     *
     * FINISHED **fica** na lista: é assim que o baseline se comporta — o evento
     * `circuito-litoral-etapa-3` aparece em `/eventos` com o selo "Finalizado".
     * Esconder seria mudança de comportamento, e paridade é regra dura
     * (CLAUDE.md §3).
     *
     * CANCELLED sai: nenhum evento cancelado existe no baseline, então não há
     * comportamento a preservar, e oferecer em vitrine de descoberta um evento
     * que não vai acontecer é convite a inscrição frustrada. A página continua
     * acessível pelo link direto, para quem já estava inscrito.
     */
    public function isListedPublicly(): bool
    {
        return $this->isPubliclyVisible() && $this !== self::CANCELLED;
    }

    /**
     * Aceita inscrição. Fase 1 só abre inscrição em REGISTRATION_OPEN — a
     * verificação de janela e de vagas é do módulo Registrations.
     */
    public function acceptsRegistrations(): bool
    {
        return $this === self::REGISTRATION_OPEN;
    }

    /**
     * Evento editável pelo organizador.
     *
     * Depois de publicado a edição continua permitida, mas alteração de data,
     * horário ou local gera obrigação de reembolso (BRIEF §35/§36) — quem trata
     * disso é a action, não este enum.
     */
    public function isEditable(): bool
    {
        return ! $this->isTerminal();
    }
}
