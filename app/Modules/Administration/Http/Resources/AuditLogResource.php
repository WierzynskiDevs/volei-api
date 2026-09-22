<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Resources;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Uma linha da trilha de auditoria.
 *
 * `metadata` sai como está gravado — e sai porque **já foi sanitizado na
 * escrita** pelo `AuditLogger`, que redige senha, token, chave e telefone antes
 * de persistir (CLAUDE.md §9 e §21). Sanitizar de novo na leitura daria a falsa
 * impressão de que a gravação pode conter segredo; a garantia é na entrada.
 *
 * `ip` e `user_agent` entram: são o que torna a trilha útil numa investigação,
 * e o leitor aqui é sempre o super admin.
 *
 * @mixin AuditLog
 */
final class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'requires_justification' => $this->action->requiresJustification(),

            'target_type' => $this->target_type,
            'target_id' => $this->target_id,

            /*
             * Ator pode ser nulo: ação de sistema (job de expiração,
             * reconciliação) não tem pessoa por trás, e inventar uma seria
             * falsificar a trilha.
             */
            'actor' => $this->whenLoaded('actor', fn (): ?array => $this->actor === null ? null : [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ]),
            'actor_role' => $this->actor_role,

            'metadata' => $this->metadata,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
