<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Events\Application\ChangeEventStatusAction;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Domain\Enums\MatchStatus;
use App\Modules\Operations\Domain\Exceptions\InvalidMatchTransitionException;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Início da partida pelo juiz (ADR 0013 §3/§4/§8 — S9).
 *
 * Exige `Idempotency-Key` (CLAUDE.md §8): sinal de rede ruim em quadra de
 * praia não pode duplicar efeito. Réplica com a MESMA chave devolve a
 * partida como está; chave diferente contra uma partida já iniciada é
 * conflito real, não repetição.
 *
 * Se é a primeira partida iniciada do evento, o `EventStatus` acompanha para
 * `IN_PROGRESS` na MESMA transação (ADR 0013 §3) — nunca chamada HTTP aqui
 * dentro (CLAUDE.md §8), só a action do módulo `Events`, que é o caminho
 * de fronteira sancionado (CLAUDE.md §4.1).
 */
final readonly class StartMatchAction
{
    public function __construct(
        private AuditLogger $audit,
        private ChangeEventStatusAction $changeEventStatus,
    ) {}

    public function execute(GameMatch $match, User $actor, string $idempotencyKey, CarbonImmutable $now): GameMatch
    {
        /** @var array{0: GameMatch, 1: bool} $result */
        $result = DB::transaction(function () use ($match, $actor, $idempotencyKey, $now): array {
            /** @var GameMatch $locked */
            $locked = GameMatch::query()->whereKey($match->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === MatchStatus::EM_ANDAMENTO) {
                if ($locked->start_idempotency_key === $idempotencyKey) {
                    return [$locked, false];
                }

                throw InvalidMatchTransitionException::from($locked->status, MatchStatus::EM_ANDAMENTO);
            }

            if (! $locked->status->canTransitionTo(MatchStatus::EM_ANDAMENTO)) {
                throw InvalidMatchTransitionException::from($locked->status, MatchStatus::EM_ANDAMENTO);
            }

            $locked->status = MatchStatus::EM_ANDAMENTO;
            $locked->started_at = $now;
            $locked->started_by = $actor->id;
            $locked->start_idempotency_key = $idempotencyKey;
            $locked->save();

            $this->advanceEventIfNeeded($locked, $actor, $now);

            return [$locked, true];
        });

        [$match, $isNewStart] = $result;

        if ($isNewStart) {
            $this->audit->log(
                action: AuditAction::MATCH_STARTED,
                actor: $actor,
                targetType: 'match',
                targetId: $match->id,
                metadata: ['event_id' => $match->event_id],
            );
        }

        return $match;
    }

    /**
     * `IN_PROGRESS` é automático, mas a transição em si é idempotente pela
     * máquina de estados: a segunda partida iniciada não encontra nada para
     * transicionar (ADR 0013 §3).
     */
    private function advanceEventIfNeeded(GameMatch $match, User $actor, CarbonImmutable $now): void
    {
        /** @var Event $event */
        $event = Event::query()->whereKey($match->event_id)->lockForUpdate()->firstOrFail();

        if (! $event->status->canTransitionTo(EventStatus::IN_PROGRESS)) {
            return;
        }

        $this->changeEventStatus->execute($event, $actor, EventStatus::IN_PROGRESS, now: $now);
    }
}
