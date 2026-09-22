<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Application\DTO;

use App\Modules\Registrations\Domain\Enums\PartnerMode;
use App\Modules\Registrations\Domain\Enums\ShirtSize;

/**
 * Entrada da criação de inscrição.
 *
 * `readonly` (CLAUDE.md §5). Note o que NÃO está aqui: nível do atleta, valor,
 * status, ocupação de vaga. Tudo isso o servidor resolve — nível vem de
 * `users.level`, valor vem de `events.registration_fee_cents`, e nenhum deles
 * aceita palpite do cliente (CLAUDE.md §2 e §27.7).
 *
 * Os cinco campos finais (Q19, ADR 0016) são só o que o atleta digitou — a
 * action decide o que o evento realmente coleta e o que fica de fora
 * (`Event::collectsRegistrationField()`), nunca este DTO.
 */
final readonly class RegistrationData
{
    public function __construct(
        public PartnerMode $partnerMode,
        /** Id do parceiro. Obrigatório e só usado no modo PARTNER. */
        public ?string $partnerUserId,
        public bool $acceptedRules,
        public ?string $emergencyContactName = null,
        public ?string $emergencyContactPhone = null,
        public ?ShirtSize $shirtSize = null,
        public ?string $teamName = null,
        public ?string $dietaryRestriction = null,
    ) {}
}
