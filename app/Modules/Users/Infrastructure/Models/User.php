<?php

declare(strict_types=1);

namespace App\Modules\Users\Infrastructure\Models;

use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Domain\Enums\PlayerLevel;
use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Domain\Enums\UserStatus;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $phone_encrypted
 * @property string|null $phone_hash
 * @property UserStatus $status
 * @property CarbonImmutable|null $birth_date
 * @property CarbonImmutable|null $terms_accepted_at
 * @property CarbonImmutable|null $privacy_accepted_at
 * @property CarbonImmutable|null $created_at
 * @property string|null $avatar_path
 * @property PlayerLevel|null $level
 * @property string|null $city
 * @property string|null $state
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    /**
     * Allowlist explícita (CLAUDE.md §5). NUNCA $guarded = [].
     *
     * `status` fica de fora de propósito: bloquear/desbloquear conta é ação
     * administrativa auditada, nunca efeito colateral de um update de perfil.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_encrypted',
        'phone_hash',
        'birth_date',
        'avatar_path',
        'level',
        'city',
        'state',
        'terms_accepted_at',
        'terms_version',
        'privacy_accepted_at',
        'privacy_version',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'phone_encrypted',
        'phone_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'birth_date' => 'date',
            'password' => 'hashed',
            // Criptografia reversível do telefone (LGPD, CLAUDE.md §12).
            'phone_encrypted' => 'encrypted',
            'status' => UserStatus::class,
            // Conjunto fechado desde a migration 2026_08_26_000100 (CLAUDE.md §5).
            'level' => PlayerLevel::class,
        ];
    }

    /**
     * A resolução automática do Laravel assume `App\Models\X` →
     * `Database\Factories\XFactory`. Com namespace modular ela erra o alvo,
     * então a fábrica é declarada explicitamente.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** @return HasMany<UserRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /** @return HasOne<Organizer, $this> */
    public function organizer(): HasOne
    {
        return $this->hasOne(Organizer::class);
    }

    /**
     * Papéis do usuário.
     *
     * @return array<int, Role>
     */
    public function roleList(): array
    {
        return $this->roles->map(fn (UserRole $r): Role => $r->role)->values()->all();
    }

    /**
     * Verificação de papel. Fonte: banco, sempre (CLAUDE.md §10).
     * Nunca aceitar papel vindo do cliente.
     */
    public function hasRole(Role $role): bool
    {
        return $this->roles->contains(fn (UserRole $r): bool => $r->role === $role);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN);
    }

    public function isBlocked(): bool
    {
        return $this->status === UserStatus::BLOCKED;
    }
}
