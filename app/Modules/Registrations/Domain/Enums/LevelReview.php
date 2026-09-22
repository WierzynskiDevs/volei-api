<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Domain\Enums;

/**
 * Análise de compatibilidade de nível (aditivo §17 e §18).
 *
 * Dimensão paralela ao `RegistrationStatus`: o aditivo §17 determina que nível
 * incompatível "NÃO bloqueia automaticamente" e cria um estado de revisão. A
 * tela `/inscricao/{slug}` já diz ao atleta que "a inscrição segue normalmente —
 * nada é bloqueado automaticamente", e é esse comportamento que o backend
 * precisa honrar.
 *
 * Consequência: uma inscrição pode estar `PENDING_PAYMENT` e `REQUIRED` ao mesmo
 * tempo. O atleta paga; o organizador decide em paralelo.
 */
enum LevelReview: string
{
    /** Nível compatível com a categoria, ou categoria sem teto. O caso comum. */
    case NOT_REQUIRED = 'NOT_REQUIRED';

    /** Nível acima da categoria. Aguarda decisão do organizador. */
    case REQUIRED = 'REQUIRED';

    case APPROVED = 'APPROVED';

    /**
     * Reprovada. O aditivo §18 exige que o capitão seja notificado e receba as
     * saídas "trocar dupla" ou "solicitar cancelamento" — e que a inscrição
     * **não** seja apagada do histórico.
     */
    case REJECTED = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::NOT_REQUIRED => 'Sem análise necessária',
            self::REQUIRED => 'Aguardando análise',
            self::APPROVED => 'Aprovada',
            self::REJECTED => 'Reprovada',
        };
    }

    /** Aparece na fila "Inscrição aguardando análise" do painel do organizador. */
    public function isPending(): bool
    {
        return $this === self::REQUIRED;
    }

    /** Só uma revisão pendente aceita decisão — decidir duas vezes é conflito. */
    public function acceptsDecision(): bool
    {
        return $this === self::REQUIRED;
    }

    public function isDecided(): bool
    {
        return $this === self::APPROVED || $this === self::REJECTED;
    }
}
