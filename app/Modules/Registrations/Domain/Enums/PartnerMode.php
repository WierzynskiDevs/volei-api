<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Domain\Enums;

/**
 * Como o atleta entrou no evento.
 *
 * São exatamente os três botões que `/inscricao/{slug}` já oferece — "Já tenho
 * parceiro", "Encontrar parceiro" e "Participar individualmente" — mapeados
 * conforme a tabela do ADR 0001. Nenhum modo foi inventado aqui.
 */
enum PartnerMode: string
{
    /** "Já tenho parceiro": cria a dupla e convida o parceiro. */
    case PARTNER = 'PARTNER';

    /** "Encontrar parceiro": entra na lista de quem procura dupla, sem grupo. */
    case SEEKING = 'SEEKING';

    /** "Participar individualmente": sorteio/rotativo. O grupo é formado depois. */
    case INDIVIDUAL = 'INDIVIDUAL';

    public function label(): string
    {
        return match ($this) {
            self::PARTNER => 'Com parceiro',
            self::SEEKING => 'Procurando parceiro',
            self::INDIVIDUAL => 'Individual',
        };
    }

    /** Só o modo "parceiro" nasce com dupla formada e exige o id do parceiro. */
    public function requiresPartner(): bool
    {
        return $this === self::PARTNER;
    }
}
