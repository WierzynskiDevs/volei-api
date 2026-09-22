<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Enums;

/**
 * Método de pagamento oferecido na Fase 1.
 *
 * O baseline mostra PIX e cartão no checkout, e `finance-data` também tem
 * boleto. `UNDEFINED` do Asaas (deixar o pagador escolher) **não** entra: a tela
 * escolhe o método antes de criar a cobrança, e um método indefinido tornaria
 * impossível informar prazo e taxa ao atleta.
 */
enum PaymentMethod: string
{
    case PIX = 'PIX';
    case CREDIT_CARD = 'CREDIT_CARD';
    case BOLETO = 'BOLETO';

    public function label(): string
    {
        return match ($this) {
            self::PIX => 'PIX',
            self::CREDIT_CARD => 'Cartão de crédito',
            self::BOLETO => 'Boleto',
        };
    }

    /**
     * Dias entre a confirmação e o dinheiro disponível, conforme a central de
     * ajuda do Asaas (docs/asaas.md §3).
     *
     * Usado só para **informar prazo em tela**. A disponibilidade real vem do
     * evento `PAYMENT_RECEIVED` do gateway — nunca deste número
     * (`CLAUDE.md` §14).
     */
    public function estimatedSettlementDays(): int
    {
        return match ($this) {
            self::PIX => 0,
            self::BOLETO => 1,
            self::CREDIT_CARD => 32,
        };
    }

    /** PIX e boleto vencem; cartão resolve na hora. */
    public function hasDueDate(): bool
    {
        return $this !== self::CREDIT_CARD;
    }
}
