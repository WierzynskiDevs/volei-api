<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/** O parceiro escolhido não pode formar dupla com quem está se inscrevendo. */
final class PartnerNotAvailableException extends DomainException
{
    public function errorCode(): string
    {
        return 'REGISTRATION_PARTNER_UNAVAILABLE';
    }

    public static function isSelf(): self
    {
        return (new self('Escolha outra pessoa como parceiro.'))
            ->withContext(['reason' => 'partner_is_self']);
    }

    public static function notFound(): self
    {
        return (new self('O parceiro escolhido não foi encontrado.'))
            ->withContext(['reason' => 'partner_not_found']);
    }

    /**
     * Conta bloqueada não entra em dupla: a inscrição do parceiro nasceria
     * impedida de pagar e a dupla ficaria presa em formação.
     */
    public static function blocked(): self
    {
        return (new self('O parceiro escolhido não pode se inscrever agora.'))
            ->withContext(['reason' => 'partner_blocked']);
    }
}
