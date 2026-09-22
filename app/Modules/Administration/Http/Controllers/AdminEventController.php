<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Modules\Administration\Http\Requests\AdministrativeReasonRequest;
use App\Modules\Administration\Http\Resources\AdminEventResource;
use App\Modules\Events\Application\ChangeEventStatusAction;
use App\Modules\Events\Application\EventDirectory;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Eventos pelo lado da administração.
 *
 * Consumidor: `/admin/eventos` — lista global com filtro por status e o botão
 * de cancelar.
 *
 * ## O admin cancela pela mesma porta que o organizador
 *
 * `cancel()` chama a `ChangeEventStatusAction` que já existia. Isso não é
 * economia de código: é o que garante que o super admin **não** consiga fazer
 * uma transição que a máquina de estados proíbe (§8). Um evento já finalizado
 * não vira cancelado porque quem pediu é admin — a regra é do domínio, não do
 * papel.
 *
 * O acesso global vem do `before()` da `EventPolicy`, e a ação fica na trilha
 * com o ator identificado (§10: "super admin tem acesso global e auditado").
 */
final class AdminEventController
{
    /** `GET /admin/events` */
    public function index(Request $request, EventDirectory $directory): AnonymousResourceCollection
    {
        /*
         * Dois filtros, e não um: `status` para um estado exato, `group` para o
         * conceito que a aba da tela representa ("Ativo" são três estados).
         * O mapa de grupos vive no enum de domínio.
         */
        $statuses = match (true) {
            $request->filled('status') => [EventStatus::from((string) $request->string('status'))],
            $request->filled('group') => EventStatus::group((string) $request->string('group')),
            default => null,
        };

        $events = $directory->paginate(
            statuses: $statuses,
            organizerId: $request->filled('organizer_id') ? (string) $request->string('organizer_id') : null,
            search: $request->filled('q') ? (string) $request->string('q') : null,
            perPage: $request->integer('per_page', 25),
        );

        return AdminEventResource::collection($events);
    }

    /**
     * `POST /admin/events/{event:slug}/cancel`
     *
     * Cancelar dispara obrigação de reembolso (§14) — daí a justificativa
     * obrigatória, que vai para `cancellation_reason` e para a auditoria.
     */
    public function cancel(
        AdministrativeReasonRequest $request,
        Event $event,
        ChangeEventStatusAction $action,
    ): AdminEventResource {
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $action->execute(
            event: $event,
            actor: $actor,
            target: EventStatus::CANCELLED,
            reason: $request->reason(),
        );

        return new AdminEventResource($event->load('organizer'));
    }
}
