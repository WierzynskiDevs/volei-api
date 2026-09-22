<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Leitura da trilha de auditoria (ADR 0010 §2).
 *
 * Consumidor: `/admin/auditoria`, que mostra data e hora, responsável, ação,
 * alvo e motivo.
 *
 * Só leitura, por definição: `audit_logs` é append-only, o banco recusa UPDATE
 * e DELETE por trigger e o model recusa por guarda (CLAUDE.md §9). Não existe —
 * e não pode passar a existir — método de escrita aqui; quem grava é o
 * `AuditLogger`, que é o ponto único.
 */
final readonly class AuditTrail
{
    private const int MAX_PER_PAGE = 100;

    /**
     * @param  list<AuditAction>|null  $actions
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(
        ?array $actions = null,
        ?string $actorId = null,
        ?string $targetType = null,
        ?string $targetId = null,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        int $perPage = 50,
    ): LengthAwarePaginator {
        return AuditLog::query()
            ->with('actor')
            ->when(
                $actions !== null && $actions !== [],
                fn (Builder $q): Builder => $q->whereIn(
                    'action',
                    array_map(fn (AuditAction $a): string => $a->value, $actions ?? []),
                ),
            )
            ->when($actorId !== null, fn (Builder $q): Builder => $q->where('actor_id', $actorId))
            ->when($targetType !== null, fn (Builder $q): Builder => $q->where('target_type', $targetType))
            ->when($targetId !== null, fn (Builder $q): Builder => $q->where('target_id', $targetId))
            ->when($from !== null, fn (Builder $q): Builder => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $q): Builder => $q->where('created_at', '<=', $to))
            /*
             * Mais recente primeiro: a trilha é lida para investigar o que
             * acabou de acontecer, não para ler a história desde o começo.
             */
            ->orderByDesc('created_at')
            ->paginate(min($perPage, self::MAX_PER_PAGE))
            ->withQueryString();
    }
}
