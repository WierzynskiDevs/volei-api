<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Events\Infrastructure\Models\Event;
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
 * O aceite agora TAMBÉM emite a "sessão escopada às partidas do juiz" que a
 * ADR 0013 §5 promete (destravado por `matches` existir, S9): um token
 * Sanctum (`EventReferee::createToken`, ADR 0013 §5 — reaproveita a infra do
 * pacote, não é mecanismo novo) devolvido em claro **uma única vez**, aqui,
 * igual ao token de convite em si. Válido até o fim do evento (+1 dia de
 * folga para partida que atrasa) — depois disso o link para de servir para
 * qualquer coisa, mesmo que alguém o guarde.
 */
final readonly class AcceptRefereeInvitationAction
{
    private const int SESSION_EXPIRY_BUFFER_DAYS = 1;

    public function __construct(private AuditLogger $audit) {}

    /** @return array{referee: EventReferee, sessionToken: string} */
    public function execute(string $rawToken, CarbonImmutable $now): array
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

        $referee->loadMissing('event');
        // FK NOT NULL garante que a relação existe; `??` cobre só o tipo, não um caso real.
        $referenceEnd = $referee->event instanceof Event ? $referee->event->end_at : $now;
        $expiresAt = $referenceEnd->addDays(self::SESSION_EXPIRY_BUFFER_DAYS);

        $sessionToken = $referee->createToken(
            name: 'referee-session',
            abilities: ['referee-session'],
            expiresAt: $expiresAt,
        )->plainTextToken;

        return ['referee' => $referee, 'sessionToken' => $sessionToken];
    }
}
