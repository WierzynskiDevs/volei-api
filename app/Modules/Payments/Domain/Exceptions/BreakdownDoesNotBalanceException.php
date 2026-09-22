<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * A decomposição financeira não fecha (CLAUDE.md §7.4).
 *
 * "Se `gross - platform_fee - asaas_fee != net`, a operação falha — não
 * 'corrige'." Esta exceção é a materialização dessa regra.
 *
 * 422 e não 500: na prática o caso que chega aqui é cobrança de valor baixo
 * demais para cobrir as taxas, o que é um problema do dado de entrada
 * (o organizador definiu R$ 1,00 de inscrição), não uma falha do sistema.
 */
final class BreakdownDoesNotBalanceException extends DomainException
{
    public function errorCode(): string
    {
        return 'PAYMENT_BREAKDOWN_UNBALANCED';
    }

    public static function platformFeeExceedsGross(int $gross, int $platformFee): self
    {
        return (new self(
            'O valor da inscrição não cobre a taxa da plataforma.'
        ))->withContext([
            'reason' => 'platform_fee_exceeds_gross',
            'gross_cents' => $gross,
            'platform_fee_cents' => $platformFee,
        ]);
    }

    public static function negativeNet(int $gross, int $platformFee, int $asaasFee, int $net): self
    {
        return (new self(
            'O valor da inscrição não cobre as taxas de cobrança.'
        ))->withContext([
            'reason' => 'negative_net',
            'gross_cents' => $gross,
            'platform_fee_cents' => $platformFee,
            'asaas_fee_cents' => $asaasFee,
            'organizer_net_cents' => $net,
        ]);
    }

    /**
     * Só acontece se a aritmética de `Money` quebrar. Mensagem genérica para o
     * cliente e contexto completo no log — é incidente, não erro de entrada.
     */
    public static function sumMismatch(int $gross, int $sum): self
    {
        return (new self(
            'Não foi possível calcular a cobrança. Tente novamente.'
        ))->withContext([
            'reason' => 'sum_mismatch',
            'gross_cents' => $gross,
            'sum_of_parts_cents' => $sum,
        ]);
    }
}
