<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\DTO;

/**
 * Situação da subconta na consulta de reconciliação (ADR 0018 §6).
 *
 * `rawStatus` é só para log/depuração — a decisão de domínio usa
 * exclusivamente `approved` (`general === "APPROVED"`, `docs/asaas.md` §8).
 * Não existe estado intermediário modelado: a doc não documenta um catálogo
 * fechado entre criação e aprovação.
 */
final readonly class AccountStatusSnapshot
{
    public function __construct(
        public bool $approved,
        public string $rawStatus,
    ) {}
}
