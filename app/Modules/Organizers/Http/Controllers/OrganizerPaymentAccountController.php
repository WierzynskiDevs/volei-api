<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Http\Controllers;

use App\Modules\Organizers\Application\RequestPaymentAccountOnboardingAction;
use App\Modules\Organizers\Http\Requests\RequestPaymentAccountRequest;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Vinculação da conta de recebimento (ADR 0018). Consumidor:
 * `/organizador/plano`, seção "Conta Asaas".
 *
 * Autorização por estado, não por Policy: o organizador só vincula a PRÓPRIA
 * conta (`$request->user()->organizer`), nunca um id vindo do cliente —
 * mesmo padrão de `OrganizerPlanController`.
 */
final class OrganizerPaymentAccountController
{
    public function __construct(private readonly RequestPaymentAccountOnboardingAction $onboarding) {}

    /** `POST /organizer/payment-account` */
    public function store(RequestPaymentAccountRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('organizer');
        $organizer = $user->organizer;

        if (! $organizer instanceof Organizer) {
            abort(403);
        }

        $organizer = $this->onboarding->execute(
            organizer: $organizer,
            actor: $user,
            mobilePhone: $request->mobilePhone(),
            monthlyIncomeCents: $request->incomeCents(),
            address: $request->addressLine(),
            addressNumber: $request->addressNumber(),
            province: $request->province(),
            postalCode: $request->postalCode(),
        );

        return new JsonResponse([
            'data' => [
                'payment_account_status' => $organizer->payment_account_status->value,
                'payment_account_status_label' => $organizer->payment_account_status->label(),
            ],
        ], 202);
    }
}
