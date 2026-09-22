<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Operations\Domain\Exceptions\CourtNotInEventException;
use App\Modules\Operations\Infrastructure\Models\Court;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Users\Infrastructure\Models\User;

/**
 * Atribui (ou remove) a quadra de um juiz (ADR 0013 §8 — S8b).
 *
 * Consumidor: seletor de quadra em `RefereesPanel`
 * (`setRefereeCourt` de `lib/operations.tsx`, hoje mock).
 */
final readonly class SetRefereeCourtAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(EventReferee $referee, User $actor, ?string $courtId): EventReferee
    {
        if ($courtId !== null) {
            $belongsToEvent = Court::query()
                ->where('id', $courtId)
                ->where('event_id', $referee->event_id)
                ->exists();

            if (! $belongsToEvent) {
                throw CourtNotInEventException::create($courtId);
            }
        }

        $referee->court_id = $courtId;
        $referee->save();

        $this->audit->log(
            action: AuditAction::REFEREE_COURT_ASSIGNED,
            actor: $actor,
            targetType: 'event_referee',
            targetId: $referee->id,
            metadata: [
                'event_id' => $referee->event_id,
                'court_id' => $courtId,
            ],
        );

        return $referee;
    }
}
