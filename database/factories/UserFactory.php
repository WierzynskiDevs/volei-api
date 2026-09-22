<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Domain\Enums\UserStatus;
use App\Modules\Users\Infrastructure\Models\User;
use App\Modules\Users\Infrastructure\Models\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /** Hash calculado uma vez: bcrypt custo 12 em cada linha deixaria a suíte lenta. */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            // Necessário: preventAccessingMissingAttributes está ativo em teste,
            // e o guard de sessão lê remember_token ao autenticar.
            'remember_token' => Str::random(10),
            'status' => UserStatus::ACTIVE,
            /*
             * Colunas anuláveis declaradas explicitamente: com
             * preventAccessingMissingAttributes ativo, um model recém-criado
             * precisa ter o mesmo conjunto de atributos de uma linha lida do
             * banco — senão o teste falha por artefato de fábrica, não por bug.
             */
            'phone_encrypted' => null,
            'phone_hash' => null,
            'birth_date' => null,
            'avatar_path' => null,
            'level' => null,
            'city' => fake()->city(),
            'state' => fake()->randomElement(['SC', 'PR', 'SP', 'RJ', 'PE', 'RN']),
            'terms_accepted_at' => now(),
            'terms_version' => '1.0',
            'privacy_accepted_at' => now(),
            'privacy_version' => '2.0',
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UserStatus::BLOCKED,
        ]);
    }

    /** Cria o usuário já com o papel indicado. */
    public function withRole(Role $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            UserRole::create(['user_id' => $user->id, 'role' => $role]);
            $user->load('roles');
        });
    }

    public function player(): static
    {
        return $this->withRole(Role::PLAYER);
    }

    public function organizer(): static
    {
        return $this->withRole(Role::ORGANIZER);
    }

    public function superAdmin(): static
    {
        return $this->withRole(Role::SUPER_ADMIN);
    }
}
