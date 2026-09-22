<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * Falha na comunicação com o gateway.
 *
 * CLAUDE.md §25: "falha de integração externa é explícita, registrada e
 * reprocessável — nunca vira sucesso". Esta exceção existe para que essa falha
 * tenha tipo próprio e não se misture com erro de validação.
 *
 * 502 e não 500: o problema está a jusante. O cliente recebe mensagem genérica;
 * o detalhe fica no `context` para o log (§11).
 */
final class PaymentProviderException extends DomainException
{
    public function errorCode(): string
    {
        return 'PAYMENT_PROVIDER_UNAVAILABLE';
    }

    public function httpStatus(): int
    {
        return 502;
    }

    public static function chargeFailed(string $provider, string $detail): self
    {
        return (new self(
            'Não foi possível gerar a cobrança agora. Tente novamente em instantes.'
        ))->withContext(['provider' => $provider, 'operation' => 'create_charge', 'detail' => $detail]);
    }

    public static function fetchFailed(string $provider, string $providerPaymentId, string $detail): self
    {
        return (new self(
            'Não foi possível consultar a cobrança agora.'
        ))->withContext([
            'provider' => $provider,
            'operation' => 'fetch_charge',
            'provider_payment_id' => $providerPaymentId,
            'detail' => $detail,
        ]);
    }

    public static function refundFailed(string $provider, string $providerPaymentId, string $detail): self
    {
        return (new self(
            'Não foi possível processar o estorno agora.'
        ))->withContext([
            'provider' => $provider,
            'operation' => 'refund',
            'provider_payment_id' => $providerPaymentId,
            'detail' => $detail,
        ]);
    }

    /**
     * Configuração ausente. Acontece quando falta credencial ou `walletId` —
     * e é erro de operação, não do usuário.
     */
    public static function misconfigured(string $provider, string $what): self
    {
        return (new self(
            'A cobrança não está configurada neste ambiente.'
        ))->withContext(['provider' => $provider, 'missing' => $what]);
    }
}
