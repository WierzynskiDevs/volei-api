<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Domain\Exceptions\MatchTeamNotEligibleException;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Registrations\Domain\Enums\GroupStatus;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use App\Modules\Users\Infrastructure\Models\User;

/**
 * Monta um confronto manualmente (ADR 0013 §1/§4/§8 — S9).
 *
 * NUNCA gerada a partir de `bracket_slots` — avanço automático de chave
 * continua Fase 2 (ADR 0011 §Consequências). O organizador escolhe as duas
 * duplas, inclusive para evento sem sorteio formal.
 */
final readonly class CreateMatchAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(Event $event, User $actor, string $teamAId, string $teamBId, ?string $phase): GameMatch
    {
        if ($teamAId === $teamBId) {
            throw MatchTeamNotEligibleException::sameTeamTwice();
        }

        $this->assertEligible($event, $teamAId);
        $this->assertEligible($event, $teamBId);

        $match = GameMatch::query()->create([
            'event_id' => $event->id,
            'team_a_id' => $teamAId,
            'team_b_id' => $teamBId,
            'phase' => $phase,
        ]);

        // `status` tem default só no banco (fora de $fillable de propósito —
        // CLAUDE.md §5): sem refresh, o model em memória fica com o atributo
        // `null` até a próxima leitura.
        $match->refresh();

        $this->audit->log(
            action: AuditAction::MATCH_CREATED,
            actor: $actor,
            targetType: 'match',
            targetId: $match->id,
            metadata: [
                'event_id' => $event->id,
                'team_a_id' => $teamAId,
                'team_b_id' => $teamBId,
            ],
        );

        return $match;
    }

    private function assertEligible(Event $event, string $teamId): void
    {
        $eligible = RegistrationGroup::query()
            ->where('id', $teamId)
            ->where('event_id', $event->id)
            ->where('status', GroupStatus::COMPLETE->value)
            ->exists();

        if (! $eligible) {
            throw MatchTeamNotEligibleException::create($teamId);
        }
    }
}
