<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Policies;

use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Infrastructure\Models\User;

/**
 * Autorização de pagamento (CLAUDE.md §10).
 *
 * Ownership por FK: `payments.user_id` para o pagador e
 * `payments.organizer_id` para o organizador. Nenhum id vem da URL.
 */
final class PaymentPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole(Role::SUPER_ADMIN) ? true : null;
    }

    /**
     * Quem pagou vê a cobrança; o organizador do evento também — ele precisa
     * conferir recebimento.
     */
    public function view(User $user, Payment $payment): bool
    {
        if ($payment->user_id === $user->id) {
            return true;
        }

        $user->loadMissing('organizer');

        return $user->organizer !== null && $user->organizer->id === $payment->organizer_id;
    }
}
