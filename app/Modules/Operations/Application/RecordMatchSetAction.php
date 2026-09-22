<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Operations\Domain\Enums\MatchStatus;
use App\Modules\Operations\Domain\Exceptions\MatchSetsNotRecordableException;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Operations\Infrastructure\Models\MatchSet;
use App\Modules\Users\Infrastructure\Models\User;

/**
 * Grava o placar final de um set (ADR 0013 §4, S9). Nunca ponto a ponto.
 *
 * Corrigir um set já gravado é permitido — gera `MATCH_SET_CORRECTED` em vez
 * de apagar o lançamento anterior (mesmo espírito append-only do CLAUDE.md
 * §9 aplicado fora do financeiro). A unique constraint `(match_id, set_number)`
 * é quem garante que não existam dois lançamentos "originais" para o mesmo set.
 */
final readonly class RecordMatchSetAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(GameMatch $match, User $actor, int $setNumber, int $scoreA, int $scoreB): MatchSet
    {
        $this->assertRecordable($match->status);

        $existing = MatchSet::query()
            ->where('match_id', $match->id)
            ->where('set_number', $setNumber)
            ->first();

        if ($existing instanceof MatchSet) {
            $existing->update(['score_a' => $scoreA, 'score_b' => $scoreB]);

            $this->audit->log(
                action: AuditAction::MATCH_SET_CORRECTED,
                actor: $actor,
                targetType: 'match',
                targetId: $match->id,
                metadata: ['event_id' => $match->event_id, 'set_number' => $setNumber, 'score_a' => $scoreA, 'score_b' => $scoreB],
            );

            return $existing;
        }

        $set = MatchSet::query()->create([
            'match_id' => $match->id,
            'set_number' => $setNumber,
            'score_a' => $scoreA,
            'score_b' => $scoreB,
        ]);

        $this->audit->log(
            action: AuditAction::MATCH_SET_RECORDED,
            actor: $actor,
            targetType: 'match',
            targetId: $match->id,
            metadata: ['event_id' => $match->event_id, 'set_number' => $setNumber, 'score_a' => $scoreA, 'score_b' => $scoreB],
        );

        return $set;
    }

    private function assertRecordable(MatchStatus $current): void
    {
        if (! in_array($current, [MatchStatus::EM_ANDAMENTO, MatchStatus::FINALIZADA], strict: true)) {
            throw MatchSetsNotRecordableException::create($current);
        }
    }
}
