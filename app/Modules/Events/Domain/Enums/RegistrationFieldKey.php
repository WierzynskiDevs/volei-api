<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Enums;

/**
 * Catálogo fechado de campos que o organizador pode exigir na inscrição
 * (Q19, ADR 0016).
 *
 * Fechado de propósito (CLAUDE.md §11, allowlist): o organizador liga/desliga
 * itens deste catálogo por evento, nunca cria campo livre. Incluir um campo
 * novo no catálogo é migration — a mesma trava que impede o CPF do §12
 * reaparecer disfarçado de "campo customizado".
 */
enum RegistrationFieldKey: string
{
    case EMERGENCY_CONTACT = 'EMERGENCY_CONTACT';
    case SHIRT_SIZE = 'SHIRT_SIZE';
    case TEAM_NAME = 'TEAM_NAME';
    case DIETARY_RESTRICTION = 'DIETARY_RESTRICTION';

    public function label(): string
    {
        return match ($this) {
            self::EMERGENCY_CONTACT => 'Contato de emergência',
            self::SHIRT_SIZE => 'Tamanho de camiseta',
            self::TEAM_NAME => 'Nome do time',
            self::DIETARY_RESTRICTION => 'Restrição alimentar',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }
}
