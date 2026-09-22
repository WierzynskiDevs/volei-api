<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Http\Controllers;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Payments\Application\PaymentDirectory;
use App\Modules\Registrations\Application\AcceptRegistrationInvitationAction;
use App\Modules\Registrations\Application\CancelRegistrationAction;
use App\Modules\Registrations\Application\CreateRegistrationAction;
use App\Modules\Registrations\Http\Requests\CreateRegistrationRequest;
use App\Modules\Registrations\Http\Resources\RegistrationResource;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Inscrição pelo lado do atleta.
 *
 * Consumidores: `/inscricao/{slug}` (criar) e `/minhas-inscricoes` (listar,
 * cancelar).
 *
 * Controller magro (CLAUDE.md §4.3): autoriza, valida e chama UMA action. O
 * instante é injetado (`CarbonImmutable::now()`) em vez de lido dentro do
 * domínio — CLAUDE.md §22 exige fixture determinística, sem `now()` implícito.
 */
final class RegistrationController
{
    private const int MAX_PER_PAGE = 50;

    /**
     * `GET /me/registrations` — consumidor: `/minhas-inscricoes`.
     *
     * A tela mostra inscrição **e** cobrança na mesma linha ("R$ 130 · pago em
     * …", "vence em …"). As duas coisas são de módulos diferentes, e é aqui que
     * elas se encontram: `Registrations` não conhece a tabela `payments`
     * (§4.1), então a cobrança é perguntada ao módulo dono e anexada à
     * inscrição, numa única query para a página inteira.
     */
    public function index(Request $request, PaymentDirectory $payments): AnonymousResourceCollection
    {
        $user = $this->userOf($request);
        $perPage = min($request->integer('per_page', 20), self::MAX_PER_PAGE);

        $registrations = Registration::query()
            ->where('user_id', $user->id)
            // Eager loading explícito: sem isto a listagem faria N+1 e
            // `preventLazyLoading` derrubaria a requisição (CLAUDE.md §6).
            ->with(['event', 'user', 'group.registrations.user'])
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        /** @var list<string> $ids */
        $ids = $registrations->getCollection()->pluck('id')->all();
        $latest = $payments->latestByRegistration($ids);

        $registrations->getCollection()->each(function (Registration $registration) use ($latest): void {
            $registration->setAttribute('latest_payment', $latest[$registration->id] ?? null);
        });

        return RegistrationResource::collection($registrations);
    }

    /**
     * `POST /events/{slug}/registrations` — "Inscrever dupla".
     *
     * O evento é resolvido por slug porque é o identificador que a tela já usa
     * na URL. Nenhum `organizer_id` nem valor vem do cliente.
     */
    public function store(
        CreateRegistrationRequest $request,
        Event $event,
        CreateRegistrationAction $action,
    ): JsonResponse {
        Gate::authorize('create', Registration::class);

        $registration = $action->execute(
            event: $event,
            actor: $this->userOf($request),
            data: $request->toData(),
            now: CarbonImmutable::now(),
        );

        return RegistrationResource::make(
            $registration->load(['event', 'user', 'group.registrations.user'])
        )->response()->setStatusCode(201);
    }

    /**
     * `POST /registrations/{registration}/accept` — Q14/ADR 0015: o parceiro
     * convidado aceita entrar na dupla. Consumidor: `/minhas-inscricoes`, onde
     * a inscrição do parceiro aparece em "Aguardando aceite do parceiro".
     */
    public function accept(
        Request $request,
        Registration $registration,
        AcceptRegistrationInvitationAction $action,
    ): JsonResponse {
        Gate::authorize('accept', $registration);

        $registration = $action->execute(
            registration: $registration,
            actor: $this->userOf($request),
            now: CarbonImmutable::now(),
        );

        return RegistrationResource::make(
            $registration->load(['event', 'user', 'group.registrations.user'])
        )->response();
    }

    /** `DELETE /registrations/{registration}` — desistência do atleta. */
    public function destroy(
        Request $request,
        Registration $registration,
        CancelRegistrationAction $action,
    ): JsonResponse {
        $registration->load('event');

        Gate::authorize('cancel', $registration);

        $registration = $action->execute(
            registration: $registration,
            actor: $this->userOf($request),
            now: CarbonImmutable::now(),
            reason: $this->reasonFrom($request),
        );

        return RegistrationResource::make(
            $registration->load(['event', 'user', 'group.registrations.user'])
        )->response();
    }

    private function reasonFrom(Request $request): ?string
    {
        $reason = trim((string) $request->string('reason'));

        return $reason === '' ? null : mb_substr($reason, 0, 1000);
    }

    private function userOf(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
