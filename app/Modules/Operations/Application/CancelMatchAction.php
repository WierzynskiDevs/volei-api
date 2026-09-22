<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Operations\Domain\Enums\MatchStatus;
use App\Modules\Operations\Domain\Exceptions\InvalidMatchTransitionException;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Users\Infrastructure\Models\User;

/**
 * Cancela uma partida que ainda não começou (ADR 0013 §3, S9). Partida
 * `EM_ANDAMENTO` não cancela nesta fatia — o juiz já está em quadra, e não
 * existe fluxo de W.O./interrupção definido (fica para quando `ADIADA`
 * ganhar transição real).
 */
final readonly class CancelMatchAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(GameMatch $match, User $actor): GameMatch
    {
        if (! $match->status->canTransitionTo(MatchStatus::CANCELADA)) {
            throw InvalidMatchTransitionException::from($match->status, MatchStatus::CANCELADA);
        }

        $match->status = MatchStatus::CANCELADA;
        $match->save();

        $this->audit->log(
            action: AuditAction::MATCH_CANCELLED,
            actor: $actor,
            targetType: 'match',
            targetId: $match->id,
            metadata: ['event_id' => $match->event_id],
        );

        return $match;
    }
}
