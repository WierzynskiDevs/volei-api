<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\DTO;

/**
 * Resultado da criação da subconta (ADR 0018).
 *
 * `apiKey` só existe aqui — devolvido em claro **uma única vez** pelo
 * gateway, nunca mais recuperável. Quem recebe este DTO tem de persistir
 * (criptografado) imediatamente ou perdê-lo para sempre.
 */
final readonly class AccountSnapshot
{
    public function __construct(
        public string $externalAccountId,
        public string $apiKey,
        public string $walletId,
    ) {}
}
