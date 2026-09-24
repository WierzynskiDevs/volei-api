<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Controllers;

use App\Modules\Operations\Application\AcceptRefereeInvitationAction;
use App\Modules\Operations\Domain\Enums\RefereeInviteStatus;
use App\Modules\Operations\Domain\Exceptions\InvalidRefereeInvitationException;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Operations\Infrastructure\Models\RefereeInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Convite do juiz — rota PÚBLICA, sem sessão (ADR 0013 §5 — S8b).
 *
 * Consumidor: tela nova de "aceitar convite" (`/juiz/convite/{token}` ou
 * equivalente — ainda não existe no volei-app, ver docs/DIVERGENCES.md).
 * O token no link é a única credencial; não há `Gate::authorize` aqui
 * porque não há usuário autenticado para autorizar.
 */
final class RefereeInvitationController
{
    public function __construct(private readonly AcceptRefereeInvitationAction $accept) {}

    /**
     * `GET /referee-invitations/{token}` — mostra para quê o link convida,
     * antes do juiz confirmar. Só o necessário para a tela: nunca telefone,
     * nunca e-mail de terceiro (CLAUDE.md §12).
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->findValid($token);

        $invitation->loadMissing(['referee.event', 'referee.court']);
        $referee = $invitation->referee;

        if (! $referee instanceof EventReferee) {
            // FK com cascadeOnDelete garante isto no banco; chegar aqui null
            // seria dado corrompido, não caminho esperado — falha alto.
            throw new RuntimeException("Convite {$invitation->id} sem juiz associado.");
        }

        return new JsonResponse([
            'data' => [
                'referee_name' => $referee->name,
                'event_name' => $referee->event?->name,
                'court_label' => $referee->court?->label,
                'already_accepted' => $referee->invite_status === RefereeInviteStatus::ACCEPTED,
            ],
        ]);
    }

    /**
     * `POST /referee-invitations/{token}/accept`.
     *
     * `session_token` vai em claro só nesta resposta — mesma regra do
     * `apiKey` de subconta Asaas (`docs/asaas.md` §8) e do próprio token de
     * convite: nunca mais recuperável depois, só o hash fica gravado.
     */
    public function accept(string $token): JsonResponse
    {
        $result = $this->accept->execute($token, CarbonImmutable::now());
        $referee = $result['referee'];

        return new JsonResponse([
            'data' => [
                'referee_name' => $referee->name,
                'accepted_at' => $referee->accepted_at?->toIso8601String(),
                'session_token' => $result['sessionToken'],
            ],
        ]);
    }

    private function findValid(string $token): RefereeInvitation
    {
        $hash = hash('sha256', $token);

        /** @var RefereeInvitation|null $invitation */
        $invitation = RefereeInvitation::query()->where('token_hash', $hash)->first();

        if ($invitation === null || $invitation->expires_at->lessThan(CarbonImmutable::now())) {
            throw InvalidRefereeInvitationException::notFoundOrExpired();
        }

        return $invitation;
    }
}
