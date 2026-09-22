<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Http\Controllers;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Payments\Application\PaymentDirectory;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Registrations\Application\ReviewRegistrationLevelAction;
use App\Modules\Registrations\Domain\Enums\LevelReview;
use App\Modules\Registrations\Domain\Enums\RegistrationStatus;
use App\Modules\Registrations\Http\Requests\ReviewLevelRequest;
use App\Modules\Registrations\Http\Resources\RegistrationResource;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Inscrições pelo lado do organizador.
 *
 * Consumidores: a fila "Inscrição aguardando análise" em `/organizador` (aditivo
 * §17) e a lista de inscritos do evento.
 *
 * A lista é sempre **derivada do evento**, e o evento passa pela `EventPolicy`.
 * Isso fecha o IDOR na raiz: não existe rota que aceite `organizer_id` nem que
 * liste inscrição sem antes provar que o evento é do solicitante (aditivo §26).
 */
final class OrganizerRegistrationController
{
    private const int MAX_PER_PAGE = 100;

    /**
     * `GET /organizer/events/{slug}/registrations`
     *
     * `?level_review=REQUIRED` alimenta a fila de análise; `?status=` alimenta
     * os filtros da tela de inscrições.
     */
    public function index(Request $request, Event $event): AnonymousResourceCollection
    {
        Gate::authorize('view', $event);

        $perPage = min($request->integer('per_page', 25), self::MAX_PER_PAGE);

        $registrations = Registration::query()
            ->where('event_id', $event->id)
            ->with(['user', 'group.registrations.user'])
            ->when(
                $request->filled('level_review'),
                fn ($q) => $q->where(
                    'level_review',
                    LevelReview::from((string) $request->string('level_review'))->value,
                ),
            )
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where(
                    'status',
                    RegistrationStatus::from((string) $request->string('status'))->value,
                ),
            )
            /*
             * Mais antiga primeiro: a fila de análise é ordem de chegada, e o
             * painel mostra "hoje, 08:12" / "ontem, 21:40" nessa sequência.
             */
            ->orderBy('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return RegistrationResource::collection($registrations);
    }

    /**
     * `GET /organizer/registrations` — fila de análise de TODOS os eventos do
     * organizador.
     *
     * Existe porque o painel `/organizador` mostra "Inscrição aguardando
     * análise" sem escolher evento — a fila é multi-evento por natureza. Sem
     * esta rota a tela teria de fazer uma requisição por evento.
     *
     * O escopo vem da sessão, nunca do cliente: `whereHas('event')` filtra pelo
     * `organizers.id` do usuário logado. Não existe parâmetro que permita ver a
     * fila de outro organizador (aditivo §26).
     */
    public function queue(Request $request, PaymentDirectory $payments): AnonymousResourceCollection
    {
        $user = $this->userOf($request);
        $user->loadMissing('organizer');
        $organizerId = $user->organizer?->id;

        $perPage = min($request->integer('per_page', 25), self::MAX_PER_PAGE);

        $query = Registration::query()
            ->with(['user', 'event', 'group.registrations.user'])
            ->when(
                $request->filled('level_review'),
                fn ($q) => $q->where(
                    'level_review',
                    LevelReview::from((string) $request->string('level_review'))->value,
                ),
            )
            ->orderBy('created_at');

        /*
         * Filtro por status de PAGAMENTO, que é o que as abas de
         * `/organizador/inscricoes` usam ("Pagos", "Pendentes", "Expirados").
         *
         * A composição acontece aqui, e em duas etapas: o módulo Payments diz
         * quais inscrições têm cobrança naquele grupo, e a query de inscrições
         * filtra por esses ids. Nenhum dos dois módulos consulta a tabela do
         * outro (§4.1).
         */
        if ($request->filled('payment_group')) {
            $statuses = PaymentStatus::group((string) $request->string('payment_group'));

            $ids = $statuses === []
                ? []
                : $payments->registrationIdsWithStatus($statuses, $organizerId);

            // Grupo conhecido sem nenhuma cobrança devolve lista vazia — não a
            // lista inteira, que faria o filtro parecer quebrado.
            $query->whereIn('id', $ids);
        }

        /*
         * Super admin vê a fila global (acesso auditado na action de decisão);
         * organizador vê só os eventos dele. Conta sem perfil de organizador
         * recebe lista vazia em vez de 403: a tela do painel é a mesma, e um
         * 403 aqui viraria erro visível numa área que a pessoa pode abrir.
         */
        if (! $user->isSuperAdmin()) {
            if ($organizerId === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('event', fn ($q) => $q->where('organizer_id', $organizerId));
            }
        }

        $registrations = $query->paginate($perPage)->withQueryString();

        /*
         * A cobrança de cada inscrição, anexada em uma query só. A tela mostra
         * valor, método e status de pagamento ao lado do jogador.
         */
        /** @var list<string> $ids */
        $ids = $registrations->getCollection()->pluck('id')->all();
        $latest = $payments->latestByRegistration($ids);

        $registrations->getCollection()->each(function (Registration $registration) use ($latest): void {
            $registration->setAttribute('latest_payment', $latest[$registration->id] ?? null);
        });

        return RegistrationResource::collection($registrations);
    }

    /** `POST /organizer/registrations/{registration}/approve` — botão "Aprovar". */
    public function approve(
        ReviewLevelRequest $request,
        Registration $registration,
        ReviewRegistrationLevelAction $action,
    ): JsonResponse {
        return $this->decide($request, $registration, $action, approve: true);
    }

    /** `POST /organizer/registrations/{registration}/reject` — botão "Reprovar". */
    public function reject(
        ReviewLevelRequest $request,
        Registration $registration,
        ReviewRegistrationLevelAction $action,
    ): JsonResponse {
        return $this->decide($request, $registration, $action, approve: false);
    }

    private function decide(
        ReviewLevelRequest $request,
        Registration $registration,
        ReviewRegistrationLevelAction $action,
        bool $approve,
    ): JsonResponse {
        // `event` carregado antes da Policy: é dele que sai o ownership.
        $registration->load('event');

        Gate::authorize('reviewLevel', $registration);

        $actor = $this->userOf($request);
        $now = CarbonImmutable::now();

        $registration = $approve
            ? $action->approve($registration, $actor, $now, $request->reason())
            : $action->reject($registration, $actor, $now, $request->reason());

        return RegistrationResource::make($registration)->response();
    }

    private function userOf(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
