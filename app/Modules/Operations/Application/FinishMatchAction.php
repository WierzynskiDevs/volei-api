<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Domain\Enums\MatchStatus;
use App\Modules\Operations\Domain\Exceptions\InvalidMatchResultException;
use App\Modules\Operations\Domain\Exceptions\InvalidMatchTransitionException;
use App\Modules\Operations\Domain\MatchOutcome;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Operations\Infrastructure\Models\MatchSet;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Encerramento da partida pelo juiz (ADR 0013 §3/§4/§8 — S9).
 *
 * Exige `Idempotency-Key`, mesmo raciocínio de `StartMatchAction`. Só
 * finaliza se os sets já gravados definirem um vencedor pela regra
 * `best_of_sets` do evento (`MatchOutcome`) — senão `InvalidMatchResultException`
 * (422): a partida não pode terminar num placar que não decide nada.
 */
final readonly class FinishMatchAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(GameMatch $match, ?User $actor, string $idempotencyKey, CarbonImmutable $now): GameMatch
    {
        /** @var array{0: GameMatch, 1: bool} $result */
        $result = DB::transaction(function () use ($match, $actor, $idempotencyKey, $now): array {
            /** @var GameMatch $locked */
            $locked = GameMatch::query()->whereKey($match->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === MatchStatus::FINALIZADA) {
                if ($locked->finish_idempotency_key === $idempotencyKey) {
                    return [$locked, false];
                }

                throw InvalidMatchTransitionException::from($locked->status, MatchStatus::FINALIZADA);
            }

            if (! $locked->status->canTransitionTo(MatchStatus::FINALIZADA)) {
                throw InvalidMatchTransitionException::from($locked->status, MatchStatus::FINALIZADA);
            }

            $locked->loadMissing('event', 'sets');

            /** @var Event $event */
            $event = $locked->event;

            if (! MatchOutcome::isDecided($this->setsOf($locked), $event->best_of_sets)) {
                throw InvalidMatchResultException::create();
            }

            $locked->status = MatchStatus::FINALIZADA;
            $locked->finished_at = $now;
            $locked->finished_by = $actor?->id;
            $locked->finish_idempotency_key = $idempotencyKey;
            $locked->save();

            return [$locked, true];
        });

        [$match, $isNewFinish] = $result;

        if ($isNewFinish) {
            $this->audit->log(
                action: AuditAction::MATCH_FINISHED,
                actor: $actor,
                targetType: 'match',
                targetId: $match->id,
                metadata: ['event_id' => $match->event_id],
            );
        }

        return $match;
    }

    /** @return list<array{score_a: int, score_b: int}> */
    private function setsOf(GameMatch $match): array
    {
        return array_values(
            $match->sets
                ->map(fn (MatchSet $set): array => ['score_a' => $set->score_a, 'score_b' => $set->score_b])
                ->all(),
        );
    }
}
