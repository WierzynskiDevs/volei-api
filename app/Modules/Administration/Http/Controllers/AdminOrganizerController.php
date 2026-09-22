<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Modules\Administration\Http\Requests\ChangeOrganizerStatusRequest;
use App\Modules\Administration\Http\Resources\AdminOrganizerResource;
use App\Modules\Events\Application\EventDirectory;
use App\Modules\Finance\Application\FinanceTotals;
use App\Modules\Finance\Application\PlatformFinanceReport;
use App\Modules\Organizers\Application\ChangeOrganizerStatusAction;
use App\Modules\Organizers\Application\OrganizerDirectory;
use App\Modules\Organizers\Domain\Enums\OrganizerStatus;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Organizadores pelo lado da administração.
 *
 * Consumidor: `/admin/organizadores`, cujas colunas são organizador, cidade,
 * **eventos**, situação e desde quando — mais os botões "Advertir" e
 * "Suspender".
 *
 * ## A composição de três módulos acontece aqui, e só aqui
 *
 * A linha da tabela junta dado de três donos: o organizador (Organizers), a
 * contagem de eventos (Events) e os totais financeiros (Finance). Cada um
 * responde pelo que é seu, e a junção é feita **em memória**, sobre a página
 * atual — nunca com JOIN cruzando fronteira de módulo (ADR 0010 §2) e nunca com
 * uma query por linha (§26).
 */
final class AdminOrganizerController
{
    /** `GET /admin/organizers` */
    public function index(
        Request $request,
        OrganizerDirectory $organizers,
        EventDirectory $events,
        PlatformFinanceReport $finance,
    ): AnonymousResourceCollection {
        $page = $organizers->paginate(
            status: $request->filled('status')
                ? OrganizerStatus::from((string) $request->string('status'))
                : null,
            search: $request->filled('q') ? (string) $request->string('q') : null,
            perPage: $request->integer('per_page', 25),
        );

        /** @var list<string> $ids */
        $ids = $page->getCollection()->pluck('id')->all();

        $eventCounts = $events->countsByOrganizer($ids);
        $financeByOrganizer = $finance->totalsByOrganizer();

        /*
         * As agregações são anexadas ao próprio model, como `withCount()` faz —
         * a coleção do paginador continua sendo de organizadores, e o Resource
         * as lê de lá. Trocar a coleção por Resources faria o paginador mentir
         * sobre o que carrega.
         */
        $page->getCollection()->each(function (Organizer $organizer) use ($eventCounts, $financeByOrganizer): void {
            $organizer->setAttribute('events_count', $eventCounts[$organizer->id] ?? 0);
            $organizer->setAttribute(
                'finance',
                $this->financePayload($financeByOrganizer[$organizer->id] ?? FinanceTotals::empty()),
            );
        });

        return AdminOrganizerResource::collection($page);
    }

    /**
     * `GET /admin/organizers/{organizer}`
     *
     * Consumidor: `/admin/organizadores/{id}`. Traz as agregações financeiras do
     * organizador já compostas — a tela mostra GMV, receita e líquido no topo.
     */
    public function show(
        string $organizer,
        OrganizerDirectory $organizers,
        EventDirectory $events,
        PlatformFinanceReport $finance,
    ): AdminOrganizerResource {
        $found = $organizers->find($organizer);

        if (! $found instanceof Organizer) {
            abort(404);
        }

        $found->setAttribute('events_count', $events->countsByOrganizer([$found->id])[$found->id] ?? 0);
        $found->setAttribute(
            'finance',
            $this->financePayload($finance->totalsByOrganizer()[$found->id] ?? FinanceTotals::empty()),
        );

        return new AdminOrganizerResource($found);
    }

    /**
     * `POST /admin/organizers/{organizer}/status`
     *
     * Uma rota para as três situações em vez de `/block`, `/warn` e `/release`:
     * é a mesma decisão de governança com destinos diferentes, e o corpo já
     * carrega justificativa. Três rotas exigiriam repetir a regra em três
     * lugares.
     */
    public function updateStatus(
        ChangeOrganizerStatusRequest $request,
        Organizer $organizer,
        ChangeOrganizerStatusAction $action,
    ): AdminOrganizerResource {
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $action->execute($organizer, $actor, $request->status(), $request->reason());

        return new AdminOrganizerResource($organizer->load(['user', 'plan']));
    }

    /** @return array<string, int|bool> */
    private function financePayload(FinanceTotals $totals): array
    {
        return [
            'gross_cents' => $totals->grossCents,
            'platform_revenue_cents' => $totals->platformRevenueCents(),
            'asaas_fee_cents' => $totals->asaasFeeCents,
            'organizer_net_cents' => $totals->organizerNetCents,
            'refunded_cents' => $totals->refundedCents,
            'chargeback_cents' => $totals->chargebackCents,
            // `false` = há pagamento confirmado sem taxa do gateway informada,
            // então o líquido ainda não fecha (ADR 0009 §5).
            'net_is_complete' => $totals->netIsComplete,
        ];
    }
}
