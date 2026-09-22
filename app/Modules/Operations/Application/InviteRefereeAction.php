<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Operations\Domain\Enums\RefereeInviteStatus;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Operations\Infrastructure\Models\RefereeInvitation;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gera o link de convite do juiz (ADR 0013 §5 — S8b).
 *
 * Mesmo padrão de token de reset de senha (ADR 0005): o valor bruto é
 * devolvido **uma única vez**, na resposta desta action — só o hash fica no
 * banco. Reenviar convite invalida qualquer token anterior ainda não usado,
 * marcando-o como consumido: o link antigo, se compartilhado sem querer,
 * para de funcionar assim que um novo é gerado.
 */
final readonly class InviteRefereeAction
{
    private const int EXPIRES_IN_HOURS = 72;

    public function __construct(private AuditLogger $audit) {}

    /** @return array{referee: EventReferee, token: string} */
    public function execute(EventReferee $referee, User $actor, CarbonImmutable $now): array
    {
        $rawToken = Str::random(48);

        DB::transaction(function () use ($referee, $rawToken, $now): void {
            RefereeInvitation::query()
                ->where('event_referee_id', $referee->id)
                ->whereNull('used_at')
                ->update(['used_at' => $now]);

            RefereeInvitation::query()->create([
                'event_referee_id' => $referee->id,
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => $now->addHours(self::EXPIRES_IN_HOURS),
                'created_at' => $now,
            ]);

            $referee->invite_status = RefereeInviteStatus::SENT;
            $referee->invited_at = $now;
            $referee->save();
        });

        $this->audit->log(
            action: AuditAction::REFEREE_INVITED,
            actor: $actor,
            targetType: 'event_referee',
            targetId: $referee->id,
            metadata: [
                'event_id' => $referee->event_id,
                // Nunca o token em claro na auditoria (CLAUDE.md §21) — nem
                // hash: quem lê o log não precisa reconstituir o convite.
            ],
        );

        return ['referee' => $referee->refresh(), 'token' => $rawToken];
    }
}
