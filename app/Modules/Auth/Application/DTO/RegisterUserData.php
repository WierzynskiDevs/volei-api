<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\DTO;

use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * Dados de cadastro, já validados na fronteira HTTP.
 *
 * A action nunca recebe Request (CLAUDE.md §4.3): assim o cadastro é testável
 * sem HTTP e reutilizável no fluxo "cadastro + inscrição" (BRIEF §12).
 *
 * NÃO existe campo de CPF, e não deve existir (LGPD, BRIEF §15).
 * NÃO existe campo de papel: papel vindo do cliente é ignorado (CLAUDE.md §10).
 */
final readonly class RegisterUserData
{
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter] public string $password,
        public ?string $phone,
        public CarbonImmutable $acceptedAt,
        public string $termsVersion,
        public string $privacyVersion,
        public ?string $birthDate = null,
        public ?string $level = null,
        public ?string $city = null,
        public ?string $state = null,
    ) {}
}
