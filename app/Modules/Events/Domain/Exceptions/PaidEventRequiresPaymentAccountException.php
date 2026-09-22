<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * Publicação de evento pago sem conta de recebimento vinculada
 * (docs/OPEN-QUESTIONS.md Q6).
 *
 * Sem isto, a plataforma permite criar um evento que cobra dinheiro sem ter
 * para onde mandá-lo. O rascunho pode existir; o que fica bloqueado é a
 * publicação, que é o momento em que o link vira público e alguém pode pagar.
 *
 * Evento gratuito (`registration_fee_cents = 0`) publica normalmente.
 */
final class PaidEventRequiresPaymentAccountException extends DomainException
{
    public function errorCode(): string
    {
        return 'EVENT_PAYMENT_ACCOUNT_REQUIRED';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function create(): self
    {
        return new self(
            'Conecte sua conta de recebimento antes de publicar um evento pago. '
            .'O evento continua salvo como rascunho.'
        );
    }
}
