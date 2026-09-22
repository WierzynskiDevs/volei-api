<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Services;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Proteção de telefone do juiz (ADR 0013 §6, CLAUDE.md §12).
 *
 * Mesmo algoritmo do `PhoneProtector` (Users\Domain\Services), não
 * reaproveitado daquele módulo de propósito — mesma decisão registrada na
 * ADR 0014 para `DocumentProtector`: módulo não importa Domain de outro
 * módulo (CLAUDE.md §4.1), a duplicação de ~30 linhas é o preço da
 * fronteira. Dedup é **por evento** aqui, não global — pepper próprio,
 * `REFEREE_PHONE_HASH_PEPPER`, separado do de usuário.
 */
final readonly class RefereePhoneProtector
{
    public function __construct(
        #[SensitiveParameter] private string $pepper,
    ) {
        if ($this->pepper === '') {
            throw new InvalidArgumentException(
                'REFEREE_PHONE_HASH_PEPPER não configurado. Sem pepper, o hash de telefone é reversível por força bruta.'
            );
        }
    }

    public function normalize(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if ($digits === '') {
            throw new InvalidArgumentException('Telefone vazio.');
        }

        if (str_starts_with($digits, '55') && strlen($digits) > 11) {
            $digits = substr($digits, 2);
        }

        $digits = ltrim($digits, '0');

        if (! preg_match('/^[1-9]\d{9,10}$/', $digits)) {
            throw new InvalidArgumentException('Telefone brasileiro inválido.');
        }

        return '+55'.$digits;
    }

    public function hash(string $phone): string
    {
        return hash_hmac('sha256', $this->normalize($phone), $this->pepper);
    }

    public function mask(string $phone): string
    {
        $normalized = $this->normalize($phone);
        $last = substr($normalized, -4);

        return '+55 •••••'.$last;
    }
}
