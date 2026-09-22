<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Registrations\Domain\Enums\LevelReview;
use App\Modules\Registrations\Domain\Exceptions\LevelReviewNotPendingException;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Decisão do organizador sobre nível incompatível (aditivo §17 e §18).
 *
 * Consumidor: o painel "Inscrição aguardando análise" em `/organizador`, que já
 * tem os botões Aprovar e Reprovar.
 *
 * O que esta action deliberadamente NÃO faz:
 *
 *  - **Não cancela a inscrição ao reprovar.** O aditivo §18 manda notificar o
 *    capitão e oferecer "trocar dupla" ou "solicitar cancelamento", e é
 *    explícito: "não excluir historicamente a inscrição". Reprovar é registrar
 *    uma decisão, não destruir o registro. Quem cancela é o atleta, por ação
 *    própria, ou o organizador pelo fluxo de cancelamento.
 *
 *  - **Não mexe em vaga.** A inscrição reprovada continua ocupando a vaga
 *    enquanto não for cancelada. Liberar a vaga aqui deixaria o atleta pagante
 *    sem vaga e sem cancelamento — pior dos dois mundos.
 *
 * A trava de duplo clique é o estado: só `REQUIRED` aceita decisão. Dois cliques
 * simultâneos produzem uma decisão e um 409, nunca duas.
 */
final readonly class ReviewRegistrationLevelAction
{
    public function __construct(private AuditLogger $audit) {}

    public function approve(
        Registration $registration,
        User $actor,
        CarbonImmutable $now,
        ?string $reason = null,
    ): Registration {
        return $this->decide($registration, $actor, LevelReview::APPROVED, $now, $reason);
    }

    public function reject(
        Registration $registration,
        User $actor,
        CarbonImmutable $now,
        ?string $reason = null,
    ): Registration {
        return $this->decide($registration, $actor, LevelReview::REJECTED, $now, $reason);
    }

    private function decide(
        Registration $registration,
        User $actor,
        LevelReview $decision,
        CarbonImmutable $now,
        ?string $reason,
    ): Registration {
        $registration = DB::transaction(function () use ($registration, $actor, $decision, $now, $reason): Registration {
            /*
             * Relê com lock: entre o carregamento no controller e aqui, outro
             * organizador (ou o mesmo, em duplo clique) pode ter decidido.
             * Sem o lock, a segunda decisão sobrescreveria a primeira e a
             * trilha perderia quem decidiu de fato (aditivo §28).
             */
            /** @var Registration $fresh */
            $fresh = Registration::query()
                ->whereKey($registration->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $fresh->level_review->acceptsDecision()) {
                throw LevelReviewNotPendingException::from($fresh->level_review);
            }

            $fresh->level_review = $decision;
            $fresh->level_review_decided_by = $actor->id;
            $fresh->level_review_decided_at = $now;
            $fresh->level_review_reason = $reason;
            $fresh->save();

            return $fresh;
        });

        $this->audit->log(
            action: AuditAction::ADMIN_ACTION,
            actor: $actor,
            targetType: 'registration',
            targetId: $registration->id,
            metadata: [
                'operation' => 'REGISTRATION_LEVEL_REVIEW',
                'decision' => $decision->value,
                'event_id' => $registration->event_id,
                'player_level' => $registration->player_level?->value,
                'event_level' => $registration->event_level->value,
                'reason' => $reason,
            ],
        );

        return $registration->load(['user', 'event', 'group.registrations.user']);
    }
}
