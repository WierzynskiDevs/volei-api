<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Domain\Enums;

/** CPF (pessoa física) ou CNPJ (pessoa jurídica) do organizador. */
enum DocumentType: string
{
    case CPF = 'CPF';
    case CNPJ = 'CNPJ';

    public function label(): string
    {
        return match ($this) {
            self::CPF => 'CPF',
            self::CNPJ => 'CNPJ',
        };
    }
}
