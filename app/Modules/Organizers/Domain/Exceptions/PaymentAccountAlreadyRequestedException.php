<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/** A conta já foi vinculada, ou o pedido de vinculação já está em andamento (ADR 0018). */
final class PaymentAccountAlreadyRequestedException extends DomainException
{
    public function errorCode(): string
    {
        return 'PAYMENT_ACCOUNT_ALREADY_REQUESTED';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function create(): self
    {
        return new self('A vinculação da conta de recebimento já foi solicitada ou concluída.');
    }
}
