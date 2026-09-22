<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Operations\Domain\Enums\MatchStatus;
use App\Modules\Operations\Domain\Exceptions\CourtNotInEventException;
use App\Modules\Operations\Domain\Exceptions\InvalidMatchTransitionException;
use App\Modules\Operations\Infrastructure\Models\Court;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Users\Infrastructure\Models\User;

/**
 * Atribui (ou remove) a quadra de uma partida — o "arrastar para a coluna"
 * do kanban (ADR 0013 §4/§8 — S9). Manual, sem ordenação automática (ATA §14).
 */
final readonly class AssignMatchCourtAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(GameMatch $match, User $actor, ?string $courtId): GameMatch
    {
        $this->assertReassignable($match->status);

        if ($courtId !== null) {
            $belongsToEvent = Court::query()
                ->where('id', $courtId)
                ->where('event_id', $match->event_id)
                ->exists();

            if (! $belongsToEvent) {
                throw CourtNotInEventException::create($courtId);
            }
        }

        $match->court_id = $courtId;
        $match->status = MatchStatus::fromAssignment($courtId, $match->referee_id);
        $match->save();

        $this->audit->log(
            action: AuditAction::MATCH_COURT_ASSIGNED,
            actor: $actor,
            targetType: 'match',
            targetId: $match->id,
            metadata: ['event_id' => $match->event_id, 'court_id' => $courtId],
        );

        return $match;
    }

    /** Só se reatribui quadra antes da partida começar (CLAUDE.md §8). */
    private function assertReassignable(MatchStatus $current): void
    {
        if (! in_array($current, [MatchStatus::PENDENTE, MatchStatus::ATRIBUIDA, MatchStatus::PRONTA], strict: true)) {
            throw InvalidMatchTransitionException::from($current, MatchStatus::ATRIBUIDA);
        }
    }
}
