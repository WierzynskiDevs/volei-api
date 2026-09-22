<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * Limite de eventos do plano atingido (BRIEF §26).
 *
 * O limite vem da tabela `plans` — nunca hardcoded (CLAUDE.md §7.8). Eventos
 * cancelados não contam: o organizador não deve ser punido por ter cancelado.
 */
final class PlanEventLimitReachedException extends DomainException
{
    public function errorCode(): string
    {
        return 'PLAN_EVENT_LIMIT_REACHED';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function forPlan(string $planName, int $limit): self
    {
        return (new self(
            "Seu plano {$planName} permite {$limit} eventos ativos. "
            .'Faça upgrade para criar mais.'
        ))->withContext(['plan' => $planName, 'event_limit' => $limit]);
    }
}
