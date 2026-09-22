<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exceptions\MoneyException;

/**
 * Valor monetário em centavos.
 *
 * CLAUDE.md §7: dinheiro é SEMPRE inteiro em centavos. Nunca float.
 * Este é o único lugar do sistema onde arredondamento monetário acontece.
 *
 * R$ 130,00 => Money::fromCents(13000)
 */
final readonly class Money
{
    private function __construct(public int $cents) {}

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Converte reais para centavos. Aceita apenas valores com no máximo 2 casas.
     *
     * Uso restrito à fronteira de entrada (ex.: valor digitado pelo organizador).
     * Nunca usar para recalcular valores já persistidos.
     */
    public static function fromReais(string $reais): self
    {
        $normalized = str_replace(',', '.', trim($reais));

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $normalized)) {
            throw MoneyException::invalidAmount($reais);
        }

        // Multiplicação em string para não passar por float em momento algum.
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');
        $negative = str_starts_with($whole, '-');
        $whole = ltrim($whole, '-');
        $fraction = str_pad($fraction, 2, '0');

        $cents = (int) $whole * 100 + (int) $fraction;

        return new self($negative ? -$cents : $cents);
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function subtract(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    /**
     * Aplica um percentual, arredondando para o centavo mais próximo (half-up).
     *
     * Half-up é a convenção única do sistema (CLAUDE.md §7). Não usar round()
     * de float em nenhum outro lugar do domínio financeiro.
     */
    public function percentage(FeeRate $rate): self
    {
        $scaled = $this->cents * $rate->basisPoints;

        // Divisão inteira com arredondamento half-up, sem float.
        $divisor = 10_000;
        $quotient = intdiv(abs($scaled), $divisor);
        $remainder = abs($scaled) % $divisor;

        if ($remainder * 2 >= $divisor) {
            $quotient++;
        }

        return new self($scaled < 0 ? -$quotient : $quotient);
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function greaterThan(self $other): bool
    {
        return $this->cents > $other->cents;
    }

    public function lessThan(self $other): bool
    {
        return $this->cents < $other->cents;
    }

    /**
     * Garante que o valor não é negativo. Usar onde negativo é invariante violada
     * (preço de inscrição, valor bruto de cobrança).
     */
    public function assertNotNegative(string $context): self
    {
        if ($this->isNegative()) {
            throw MoneyException::negativeNotAllowed($context, $this->cents);
        }

        return $this;
    }

    /**
     * Representação para apresentação. NUNCA usar o resultado disto em cálculo
     * nem persistir — a persistência é sempre `cents`.
     */
    public function toReais(): string
    {
        $negative = $this->cents < 0;
        $abs = abs($this->cents);

        return ($negative ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
