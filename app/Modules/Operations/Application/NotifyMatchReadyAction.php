<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Notifications\Application\NotificationDispatcher;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Operations\Domain\Enums\MatchStatus;
use App\Modules\Operations\Infrastructure\Models\Court;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use App\Modules\Users\Infrastructure\Models\User;

/**
 * "Atribuição de partida" (B4, `docs/PLANO-CONTINUACAO-2026-09.md`).
 *
 * Chamada por `AssignMatchCourtAction`/`AssignMatchRefereeAction` depois de
 * gravar — nunca dispara sozinha. Só notifica na transição PARA `PRONTA`
 * (quadra e juiz definidos): é o momento em que o jogador tem algo acionável
 * ("sua partida vai ser aqui"), não em cada atribuição parcial isolada, o que
 * duplicaria e-mail (uma quadra reatribuída depois de já pronta não reabre
 * aviso).
 */
final readonly class NotifyMatchReadyAction
{
    public function __construct(private NotificationDispatcher $notifications) {}

    public function execute(GameMatch $match, MatchStatus $previousStatus): void
    {
        if ($previousStatus === MatchStatus::PRONTA || $match->status !== MatchStatus::PRONTA) {
            return;
        }

        $match->loadMissing(['court', 'teamA.registrations.user', 'teamB.registrations.user', 'event']);

        $event = $match->event;

        if (! $event instanceof Event) {
            // FK NOT NULL garante isto no banco; chegar aqui seria dado
            // corrompido, não caminho esperado — não notifica com dado quebrado.
            return;
        }

        $court = $match->court;
        $courtLabel = $court instanceof Court ? $court->label : 'quadra a confirmar';

        foreach ($this->players($match) as $player) {
            $this->notifications->queueEmail(
                type: NotificationType::MATCH_READY,
                recipient: $player,
                subject: "Sua partida está pronta — {$event->name}",
                body: "Sua partida ({$match->phase}) já tem quadra e juiz definidos: {$courtLabel}. "
                    .'Acompanhe o horário pelo painel operacional do evento.',
                metadata: ['event_id' => $match->event_id, 'match_id' => $match->id],
            );
        }
    }

    /** @return list<User> */
    private function players(GameMatch $match): array
    {
        $users = [];

        foreach ([$match->teamA, $match->teamB] as $team) {
            if (! $team instanceof RegistrationGroup || ! $team->relationLoaded('registrations')) {
                continue;
            }

            foreach ($team->registrations as $registration) {
                /** @var Registration $registration */
                if ($registration->relationLoaded('user') && $registration->user instanceof User) {
                    $users[$registration->user->id] = $registration->user;
                }
            }
        }

        return array_values($users);
    }
}
