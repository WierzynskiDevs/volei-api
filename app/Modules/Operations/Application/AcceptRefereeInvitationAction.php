<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Operations\Domain\Enums\RefereeInviteStatus;
use App\Modules\Operations\Domain\Exceptions\InvalidRefereeInvitationException;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Operations\Infrastructure\Models\RefereeInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * O juiz aceita o convite (ADR 0013 §5 — S8b).
 *
 * Rota pública, sem sessão Sanctum — o token no link **é** a credencial,
 * mesmo padrão do reset de senha (ADR 0005). Por isso `AuditLogger` recebe
 * `actor: null` aqui: não há usuário autenticado, só o portador do link.
 *
 * A "sessão escopada às partidas do juiz" que a ADR 0013 §5 promete só faz
 * sentido quando `matches` existir (S9) — até lá, aceitar só confirma
 * presença; não há o que visualizar depois de aceitar.
 */
final readonly class AcceptRefereeInvitationAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(string $rawToken, CarbonImmutable $now): EventReferee
    {
        $hash = hash('sha256', $rawToken);

        $referee = DB::transaction(function () use ($hash, $now): EventReferee {
            /** @var RefereeInvitation|null $invitation */
            $invitation = RefereeInvitation::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($invitation === null || $invitation->expires_at->lessThan($now)) {
                throw InvalidRefereeInvitationException::notFoundOrExpired();
            }

            if ($invitation->used_at !== null) {
                throw InvalidRefereeInvitationException::alreadyUsed();
            }

            $invitation->used_at = $now;
            $invitation->save();

            /** @var EventReferee $referee */
            $referee = EventReferee::query()
                ->whereKey($invitation->event_referee_id)
                ->lockForUpdate()
                ->firstOrFail();

            $referee->invite_status = RefereeInviteStatus::ACCEPTED;
            $referee->accepted_at = $now;
            $referee->save();

            return $referee;
        });

        $this->audit->log(
            action: AuditAction::REFEREE_INVITATION_ACCEPTED,
            actor: null,
            targetType: 'event_referee',
            targetId: $referee->id,
            metadata: [
                'event_id' => $referee->event_id,
            ],
        );

        return $referee;
    }
}
