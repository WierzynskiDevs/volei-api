<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Enums;

/**
 * Estado do processamento de um evento de webhook (CLAUDE.md §14).
 *
 * O evento é persistido **antes** de ser processado. Este enum diz em que ponto
 * do processamento ele está — e é o que garante que "falha de processamento não
 * perde o evento".
 */
enum WebhookProcessingStatus: string
{
    /** Gravado, aguardando o job. É neste estado que respondemos 200 ao Asaas. */
    case PENDING = 'PENDING';

    case PROCESSING = 'PROCESSING';

    case PROCESSED = 'PROCESSED';

    /** Processamento falhou. Fica para reprocessamento e alerta. */
    case FAILED = 'FAILED';

    /**
     * Evento reconhecido mas sem efeito no domínio.
     *
     * O Asaas manda 20+ tipos de evento de cobrança (docs/asaas.md §4);
     * `PAYMENT_BANK_SLIP_VIEWED` e `PAYMENT_CHECKOUT_VIEWED`, por exemplo, não
     * mudam nada aqui. Marcar como ignorado é diferente de falhar: mantém a
     * trilha sem gerar alerta falso.
     */
    case IGNORED = 'IGNORED';

    public function isDone(): bool
    {
        return $this === self::PROCESSED || $this === self::IGNORED;
    }

    public function needsAttention(): bool
    {
        return $this === self::FAILED;
    }
}
