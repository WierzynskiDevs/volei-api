<?php

declare(strict_types=1);

namespace App\Modules\Users\Infrastructure\Models;

use App\Modules\Users\Domain\Enums\Role;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Papel atribuído a um usuário.
 *
 * Tabela separada de propósito: o baseline tem contas com PLAYER + ORGANIZER
 * simultaneamente, e papel como coluna em `users` impediria isso.
 *
 * @property string $id
 * @property string $user_id
 * @property Role $role
 */
final class UserRole extends Model
{
    use HasUuids;

    protected $table = 'user_roles';

    protected $fillable = [
        'user_id',
        'role',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
