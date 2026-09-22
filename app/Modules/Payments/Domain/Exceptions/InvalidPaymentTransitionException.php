<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Shared\Domain\Exceptions\DomainException;

/**
 * Transição inválida no pagamento (CLAUDE.md §8).
 *
 * O caso que mais importa: webhook fora de ordem. O Asaas não garante ordem
 * (docs/asaas.md §4), então um `PAYMENT_CONFIRMED` pode chegar **depois** de um
 * `PAYMENT_RECEIVED`. Aplicar o atrasado faria o pagamento voltar de "recebido"
 * para "pago" e o dinheiro sair do disponível.
 *
 * Por isso o processamento do webhook **não** trata isto como erro: marca o
 * evento como processado-fora-de-ordem e segue. A exceção existe para o caminho
 * de ação direta, onde a transição errada é bug e tem de aparecer.
 */
final class InvalidPaymentTransitionException extends DomainException
{
    public function errorCode(): string
    {
        return 'PAYMENT_INVALID_TRANSITION';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function from(PaymentStatus $current, PaymentStatus $target): self
    {
        return (new self(
            "Um pagamento em \"{$current->label()}\" não pode ir para \"{$target->label()}\"."
        ))->withContext([
            'current_status' => $current->value,
            'target_status' => $target->value,
        ]);
    }
}
