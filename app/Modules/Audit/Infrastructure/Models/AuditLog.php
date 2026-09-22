<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Models;

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Registro de auditoria. APPEND-ONLY (CLAUDE.md §9).
 *
 * O banco já bloqueia UPDATE/DELETE/TRUNCATE por trigger; estas guardas existem
 * para que a violação apareça como erro claro de aplicação, e não como erro de
 * driver vindo do Postgres.
 *
 * @property string $id
 * @property AuditAction $action
 */
final class AuditLog extends Model
{
    use HasUuids;

    protected $table = 'audit_logs';

    /** Sem updated_at: o registro nunca muda. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'actor_role',
        'action',
        'target_type',
        'target_id',
        'metadata',
        'ip',
        'user_agent',
        'request_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('audit_logs é append-only: alteração não é permitida.');
        });

        self::deleting(function (): never {
            throw new RuntimeException('audit_logs é append-only: exclusão não é permitida.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
