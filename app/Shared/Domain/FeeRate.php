<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exceptions\MoneyException;

/**
 * Taxa percentual, armazenada em basis points (1 bp = 0,01%).
 *
 * CLAUDE.md §7: taxa nunca é float. 5% => 500 bp. 3,5% => 350 bp. 2,5% => 250 bp.
 *
 * A taxa é CONGELADA no pagamento (CLAUDE.md §7.5): o valor em basis points é
 * persistido junto da cobrança e jamais recalculado depois.
 */
final readonly class FeeRate
{
    private function __construct(public int $basisPoints) {}

    public static function fromBasisPoints(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw MoneyException::negativeFeeRate($basisPoints);
        }

        if ($basisPoints > 10_000) {
            throw MoneyException::feeRateAboveHundredPercent($basisPoints);
        }

        return new self($basisPoints);
    }

    /**
     * Aceita percentual como string para não passar por float.
     * "5" => 500 bp; "3.5" => 350 bp; "2,5" => 250 bp.
     */
    public static function fromPercent(string $percent): self
    {
        $normalized = str_replace(',', '.', trim($percent));

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $normalized)) {
            throw MoneyException::invalidFeeRate($percent);
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');
        $fraction = str_pad($fraction, 2, '0');

        return self::fromBasisPoints((int) $whole * 100 + (int) $fraction);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function isZero(): bool
    {
        return $this->basisPoints === 0;
    }

    public function equals(self $other): bool
    {
        return $this->basisPoints === $other->basisPoints;
    }

    /** Apresentação apenas. "5" / "3.5" */
    public function toPercent(): string
    {
        $whole = intdiv($this->basisPoints, 100);
        $fraction = $this->basisPoints % 100;

        if ($fraction === 0) {
            return (string) $whole;
        }

        return rtrim($whole.'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0');
    }
}
