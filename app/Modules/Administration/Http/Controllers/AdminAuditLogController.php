<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Modules\Administration\Http\Resources\AuditLogResource;
use App\Modules\Audit\Application\AuditTrail;
use App\Modules\Audit\Domain\Enums\AuditAction;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Trilha de auditoria.
 *
 * Consumidor: `/admin/auditoria` — "Data e hora · Responsável · Ação · Alvo ·
 * Motivo".
 *
 * Só `index`. Não existe `store`, `update` nem `destroy`, e isso não é omissão:
 * `audit_logs` é append-only, quem grava é o `AuditLogger` como ponto único, e o
 * banco recusa alteração por trigger (§9). Um endpoint de escrita aqui seria uma
 * porta para forjar trilha.
 */
final class AdminAuditLogController
{
    /** `GET /admin/audit-logs` */
    public function index(Request $request, AuditTrail $trail): AnonymousResourceCollection
    {
        /** @var list<AuditAction> $actions */
        $actions = [];

        // Aceita `?action=EVENT_CANCELLED` ou `?action[]=A&action[]=B`:
        // a investigação normalmente olha um conjunto de ações relacionadas.
        foreach ((array) $request->input('action', []) as $value) {
            if (is_string($value) && $value !== '') {
                $actions[] = AuditAction::from($value);
            }
        }

        $logs = $trail->paginate(
            actions: $actions,
            actorId: $request->filled('actor_id') ? (string) $request->string('actor_id') : null,
            targetType: $request->filled('target_type') ? (string) $request->string('target_type') : null,
            targetId: $request->filled('target_id') ? (string) $request->string('target_id') : null,
            from: $request->filled('from') ? CarbonImmutable::parse((string) $request->string('from')) : null,
            to: $request->filled('to') ? CarbonImmutable::parse((string) $request->string('to')) : null,
            perPage: $request->integer('per_page', 50),
        );

        return AuditLogResource::collection($logs);
    }
}
