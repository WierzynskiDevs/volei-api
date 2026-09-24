<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * Falha na comunicação com o gateway ao provisionar conta (ADR 0018).
 *
 * Mesmo raciocínio de `PaymentProviderException`: falha externa é explícita
 * e nunca vira sucesso (CLAUDE.md §25). 502 porque o problema está a jusante.
 */
final class PaymentAccountProviderException extends DomainException
{
    public function errorCode(): string
    {
        return 'PAYMENT_ACCOUNT_PROVIDER_UNAVAILABLE';
    }

    public function httpStatus(): int
    {
        return 502;
    }

    public static function createFailed(string $provider, string $detail): self
    {
        return (new self(
            'Não foi possível abrir a conta de recebimento agora. Tente novamente em instantes.'
        ))->withContext(['provider' => $provider, 'operation' => 'create_account', 'detail' => $detail]);
    }

    public static function fetchFailed(string $provider, string $externalAccountId, string $detail): self
    {
        return (new self(
            'Não foi possível consultar a situação da conta agora.'
        ))->withContext([
            'provider' => $provider,
            'operation' => 'fetch_account_status',
            'external_account_id' => $externalAccountId,
            'detail' => $detail,
        ]);
    }

    public static function misconfigured(string $provider, string $what): self
    {
        return (new self(
            'A abertura de conta não está configurada neste ambiente.'
        ))->withContext(['provider' => $provider, 'missing' => $what]);
    }
}
