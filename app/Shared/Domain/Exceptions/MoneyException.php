<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exceptions;

/**
 * Violações de invariante monetária.
 *
 * Estas exceções indicam BUG, não entrada inválida de usuário — a validação de
 * entrada acontece antes, no FormRequest. Se uma delas chega ao handler HTTP,
 * algo passou pela validação que não deveria.
 */
final class MoneyException extends DomainException
{
    public function errorCode(): string
    {
        return 'MONEY_INVARIANT_VIOLATION';
    }

    public function httpStatus(): int
    {
        return 500;
    }

    public static function invalidAmount(string $value): self
    {
        return (new self('Valor monetário inválido.'))
            ->withContext(['value' => $value]);
    }

    public static function negativeNotAllowed(string $context, int $cents): self
    {
        return (new self("Valor monetário negativo não permitido em: {$context}."))
            ->withContext(['context' => $context, 'cents' => $cents]);
    }

    public static function invalidFeeRate(string $value): self
    {
        return (new self('Taxa percentual inválida.'))
            ->withContext(['value' => $value]);
    }

    public static function negativeFeeRate(int $basisPoints): self
    {
        return (new self('Taxa percentual não pode ser negativa.'))
            ->withContext(['basis_points' => $basisPoints]);
    }

    public static function feeRateAboveHundredPercent(int $basisPoints): self
    {
        return (new self('Taxa percentual não pode ultrapassar 100%.'))
            ->withContext(['basis_points' => $basisPoints]);
    }
}
