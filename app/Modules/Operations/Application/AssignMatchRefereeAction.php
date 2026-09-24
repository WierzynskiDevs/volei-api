<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Operations\Domain\Enums\MatchStatus;
use App\Modules\Operations\Domain\Exceptions\InvalidMatchTransitionException;
use App\Modules\Operations\Domain\Exceptions\RefereeNotInEventException;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Users\Infrastructure\Models\User;

/** Atribui (ou remove) o juiz de uma partida (ADR 0013 §4/§8 — S9). */
final readonly class AssignMatchRefereeAction
{
    public function __construct(
        private AuditLogger $audit,
        private NotifyMatchReadyAction $notifyReady,
    ) {}

    public function execute(GameMatch $match, User $actor, ?string $refereeId): GameMatch
    {
        $this->assertReassignable($match->status);

        if ($refereeId !== null) {
            $belongsToEvent = EventReferee::query()
                ->where('id', $refereeId)
                ->where('event_id', $match->event_id)
                ->exists();

            if (! $belongsToEvent) {
                throw RefereeNotInEventException::create($refereeId);
            }
        }

        $previousStatus = $match->status;
        $match->referee_id = $refereeId;
        $match->status = MatchStatus::fromAssignment($match->court_id, $refereeId);
        $match->save();

        $this->audit->log(
            action: AuditAction::MATCH_REFEREE_ASSIGNED,
            actor: $actor,
            targetType: 'match',
            targetId: $match->id,
            metadata: ['event_id' => $match->event_id, 'referee_id' => $refereeId],
        );

        $this->notifyReady->execute($match, $previousStatus);

        return $match;
    }

    private function assertReassignable(MatchStatus $current): void
    {
        if (! in_array($current, [MatchStatus::PENDENTE, MatchStatus::ATRIBUIDA, MatchStatus::PRONTA], strict: true)) {
            throw InvalidMatchTransitionException::from($current, MatchStatus::PRONTA);
        }
    }
}
