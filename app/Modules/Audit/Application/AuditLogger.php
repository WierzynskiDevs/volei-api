<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ponto único de gravação da trilha de auditoria (CLAUDE.md §9).
 *
 * Duas regras que este serviço garante:
 *  1. Nenhum segredo entra em `metadata` — a sanitização é obrigatória e
 *     acontece aqui, não na confiança de quem chama.
 *  2. Falha ao auditar NUNCA derruba a operação de negócio, mas também nunca
 *     passa silenciosa: vira log de erro para alerta.
 */
final readonly class AuditLogger
{
    /**
     * Chaves que jamais podem ser persistidas na trilha, em qualquer nível
     * do array. Comparação por substring, minúscula.
     */
    private const array FORBIDDEN_KEY_FRAGMENTS = [
        'password',
        'senha',
        'token',
        'secret',
        'api_key',
        'apikey',
        'authorization',
        'credit_card',
        'card_number',
        'cvv',
        'phone_encrypted',
        'phone_hash',
        'remember_token',
        'access_key',
    ];

    public function __construct(private Request $request) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        AuditAction $action,
        ?User $actor = null,
        ?string $targetType = null,
        ?string $targetId = null,
        array $metadata = [],
    ): void {
        try {
            AuditLog::create([
                'actor_id' => $actor?->id,
                /*
                 * `?? null` e não `[0]` direto: ator sem nenhum papel existe
                 * (conta recém-criada antes do papel ser gravado, ator de
                 * sistema) e `[0]` estourava "Undefined array key 0". Como o
                 * catch abaixo engole a exceção, o efeito era a trilha
                 * desaparecer em silêncio — exatamente o que auditoria não pode
                 * fazer.
                 */
                'actor_role' => ($actor?->roleList()[0] ?? null)?->value,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'metadata' => $this->sanitize($metadata),
                'ip' => $this->request->ip(),
                'user_agent' => substr((string) $this->request->userAgent(), 0, 1000),
                'request_id' => $this->request->attributes->get('request_id'),
            ]);
        } catch (Throwable $e) {
            // A operação de negócio não pode falhar porque a auditoria falhou —
            // mas isto é um incidente e precisa de alerta.
            Log::channel('audit')->error('Falha ao gravar audit_log', [
                'action' => $action->value,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove recursivamente qualquer chave sensível.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function sanitize(array $data, int $depth = 0): array
    {
        if ($depth > 10) {
            return ['_truncated' => 'profundidade máxima excedida'];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isForbidden($key)) {
                $clean[$key] = '[REDACTED]';

                continue;
            }

            $clean[$key] = is_array($value)
                ? $this->sanitize($value, $depth + 1)
                : $value;
        }

        return $clean;
    }

    private function isForbidden(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::FORBIDDEN_KEY_FRAGMENTS as $fragment) {
            if (str_contains($needle, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
