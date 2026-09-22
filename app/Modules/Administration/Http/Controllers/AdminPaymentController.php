<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Modules\Administration\Http\Resources\AdminPaymentResource;
use App\Modules\Payments\Application\PaymentDirectory;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Cobranças pelo lado da administração.
 *
 * Consumidores: `/admin/pagamentos` (lista com as abas Todos · Pagos ·
 * Pendentes · Falhos · Reembolsados · Chargebacks) e
 * `/admin/pagamentos/{id}` (detalhe).
 *
 * Só leitura, e isso é deliberado: não existe endpoint administrativo que mude
 * status de pagamento. Quem move o estado do dinheiro é o webhook verificado ou
 * a reconciliação (§14) — um botão de "marcar como pago" no painel seria
 * exatamente a confirmação visual que o contrato proíbe.
 */
final class AdminPaymentController
{
    /** `GET /admin/payments` */
    public function index(Request $request, PaymentDirectory $directory): AnonymousResourceCollection
    {
        $payments = $directory->paginate(
            // Grupo vazio = sem filtro. O mapa de grupos vive no enum de
            // domínio, não aqui.
            statuses: $request->filled('group')
                ? PaymentStatus::group((string) $request->string('group'))
                : null,
            organizerId: $request->filled('organizer_id') ? (string) $request->string('organizer_id') : null,
            eventId: $request->filled('event_id') ? (string) $request->string('event_id') : null,
            perPage: $request->integer('per_page', 25),
        );

        return AdminPaymentResource::collection($payments);
    }

    /** `GET /admin/payments/{payment}` */
    public function show(string $payment, PaymentDirectory $directory): AdminPaymentResource
    {
        $found = $directory->find($payment);

        if ($found === null) {
            abort(404);
        }

        return new AdminPaymentResource($found);
    }
}
