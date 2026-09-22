<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

use App\Modules\Registrations\Domain\Enums\RegistrationStatus;
use App\Shared\Domain\Exceptions\DomainException;

/** A inscrição não admite cobrança agora. */
final class PaymentNotAllowedException extends DomainException
{
    public function errorCode(): string
    {
        return 'PAYMENT_NOT_ALLOWED';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function alreadyConfirmed(): self
    {
        return (new self('Esta inscrição já está paga.'))
            ->withContext(['reason' => 'registration_already_confirmed']);
    }

    public static function registrationTerminal(RegistrationStatus $status): self
    {
        return (new self(
            "Não é possível pagar uma inscrição {$status->label()}."
        ))->withContext(['reason' => 'registration_terminal', 'registration_status' => $status->value]);
    }

    /** Evento gratuito confirma na inscrição e não gera cobrança (ADR 0001). */
    public static function freeEvent(): self
    {
        return (new self('Este campeonato é gratuito e não exige pagamento.'))
            ->withContext(['reason' => 'free_event']);
    }

    public static function alreadyHasOpenCharge(): self
    {
        return (new self('Já existe uma cobrança em aberto para esta inscrição.'))
            ->withContext(['reason' => 'open_charge_exists']);
    }

    public static function eventMissing(): self
    {
        return (new self('Não foi possível identificar o campeonato desta inscrição.'))
            ->withContext(['reason' => 'event_or_organizer_missing']);
    }
}
