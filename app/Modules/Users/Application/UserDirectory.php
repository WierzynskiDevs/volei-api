<?php

declare(strict_types=1);

namespace App\Modules\Users\Application;

use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Domain\Enums\UserStatus;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Consulta de usuários para uso administrativo (ADR 0010 §2).
 *
 * Existe para que `Administration` não monte query sobre a tabela `users`:
 * quem define o que é "um jogador", "uma conta suspensa" e o que pode ser
 * buscado é o módulo dono do dado (CLAUDE.md §4.1).
 *
 * ## O que NÃO é buscável aqui
 *
 * Telefone. `phone_hash` é HMAC determinístico e serve para deduplicação, não
 * para varredura: permitir busca por telefone daria ao painel um caminho de
 * consulta por dado pessoal protegido, que o §12 proíbe usar como chave. Nome e
 * e-mail bastam para a tela `/admin/usuarios`, que é o consumidor.
 */
final readonly class UserDirectory
{
    private const int MAX_PER_PAGE = 100;

    /**
     * Os filtros espelham as abas da tela: Todos · Jogador · Organizador ·
     * Ambos · Ativo · Suspenso.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(
        ?Role $role = null,
        ?UserStatus $status = null,
        ?string $search = null,
        bool $onlyBothRoles = false,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $query = User::query()
            ->with(['roles', 'organizer.plan'])
            ->when($status !== null, fn (Builder $q): Builder => $q->where('status', $status?->value))
            ->when(
                $role !== null,
                fn (Builder $q): Builder => $q->whereHas('roles', fn (Builder $r): Builder => $r->where('role', $role?->value)),
            );

        /*
         * "Ambos" é a conta que acumula os dois papéis — a tela `/escolher-perfil`
         * existe para ela. Não é um papel: é a interseção, e por isso não cabe
         * no filtro `role`.
         */
        if ($onlyBothRoles) {
            $query
                ->whereHas('roles', fn (Builder $r): Builder => $r->where('role', Role::PLAYER->value))
                ->whereHas('roles', fn (Builder $r): Builder => $r->where('role', Role::ORGANIZER->value));
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%'.trim($search).'%';

            // Parametrizado, sempre (CLAUDE.md §11). ILIKE é do Postgres e é o
            // que faz a busca ignorar maiúsculas sem depender de collation.
            $query->where(function (Builder $q) use ($term): void {
                $q->where('name', 'ILIKE', $term)
                    ->orWhere('email', 'ILIKE', $term);
            });
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate(min($perPage, self::MAX_PER_PAGE))
            ->withQueryString();
    }

    /** Contagens do topo de `/admin` — uma query por recorte, sem carregar linhas. */
    public function counts(): UserCounts
    {
        return new UserCounts(
            total: User::query()->count(),
            players: User::query()->whereHas('roles', fn (Builder $r): Builder => $r->where('role', Role::PLAYER->value))->count(),
            organizers: User::query()->whereHas('roles', fn (Builder $r): Builder => $r->where('role', Role::ORGANIZER->value))->count(),
            blocked: User::query()->where('status', UserStatus::BLOCKED->value)->count(),
        );
    }
}
