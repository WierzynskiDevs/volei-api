<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Application\CreatePaymentAction;
use App\Modules\Payments\Http\Requests\CreatePaymentRequest;
use App\Modules\Payments\Http\Resources\PaymentResource;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Users\Infrastructure\Models\User;
use App\Shared\Http\Exceptions\MissingIdempotencyKeyException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Cobrança de uma inscrição.
 *
 * Consumidor: `/checkout/{slug}` no volei-app.
 *
 * Controller magro (CLAUDE.md §4.3). A única lógica aqui é extrair a
 * `Idempotency-Key` do header, porque isso é protocolo HTTP e não regra de
 * negócio.
 */
final class PaymentController
{
    /**
     * `POST /registrations/{registration}/payments`
     *
     * Exige header `Idempotency-Key` (CLAUDE.md §8). Chave repetida devolve a
     * **mesma** resposta, sem criar nova cobrança no gateway — é o que impede
     * que duplo clique no checkout gere duas cobranças para a mesma inscrição.
     */
    public function store(
        CreatePaymentRequest $request,
        Registration $registration,
        CreatePaymentAction $action,
    ): JsonResponse {
        $registration->load('event.organizer.plan');

        Gate::authorize('pay', $registration);

        $payment = $action->execute(
            registration: $registration,
            actor: $this->userOf($request),
            method: $request->paymentMethod(),
            idempotencyKey: $this->idempotencyKeyOf($request),
            now: CarbonImmutable::now(),
        );

        /*
         * 201 sempre, inclusive na repetição da chave. O §8 pede a "mesma
         * resposta" — e a resposta da criação é 201. Devolver 200 na segunda
         * chamada faria o cliente distinguir os casos, que é justamente o que a
         * idempotência existe para evitar.
         */
        return PaymentResource::make($payment)->response()->setStatusCode(201);
    }

    /** `GET /payments/{payment}` — o checkout consulta o backend, nunca o gateway. */
    public function show(Payment $payment): JsonResponse
    {
        $payment->load('registration.event');

        Gate::authorize('view', $payment);

        return PaymentResource::make($payment)->response();
    }

    /**
     * A chave é obrigatória e vem do header.
     *
     * Sem ela não há como garantir que a cobrança não duplique, então a
     * requisição é recusada em vez de aceita com risco.
     */
    private function idempotencyKeyOf(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            throw MissingIdempotencyKeyException::create();
        }

        return mb_substr($key, 0, 128);
    }

    private function userOf(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
