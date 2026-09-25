<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Infrastructure\Models\User;
use App\Modules\Users\Infrastructure\Models\UserRole;
use Illuminate\Console\Command;

/**
 * Provisionamento administrativo explícito (CLAUDE.md §10) — único caminho
 * para conceder SUPER_ADMIN, já que o papel nunca é autoatribuível
 * (Role::isSelfAssignable()). Uso raro/manual: bootstrap do primeiro admin
 * de um ambiente, executado via acesso direto ao servidor (nunca por rota
 * HTTP — não existe endpoint para isto, de propósito).
 */
final class PromoteSuperAdminCommand extends Command
{
    protected $signature = 'users:promote-super-admin {email}';

    protected $description = 'Concede o papel SUPER_ADMIN a um usuário existente pelo e-mail';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->error("Usuário não encontrado: {$email}");

            return self::FAILURE;
        }

        UserRole::query()->firstOrCreate([
            'user_id' => $user->id,
            'role' => Role::SUPER_ADMIN,
        ]);

        $this->info("OK: {$email} agora é SUPER_ADMIN.");

        return self::SUCCESS;
    }
}
