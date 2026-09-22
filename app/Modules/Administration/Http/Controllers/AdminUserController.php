<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Modules\Administration\Http\Requests\AdministrativeReasonRequest;
use App\Modules\Administration\Http\Resources\AdminUserResource;
use App\Modules\Users\Application\ChangeUserStatusAction;
use App\Modules\Users\Application\UserDirectory;
use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Domain\Enums\UserStatus;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Usuários pelo lado da administração.
 *
 * Consumidor: `/admin/usuarios` — lista com as abas Todos · Jogador ·
 * Organizador · Ambos · Ativo · Suspenso, e as ações de suspender e reativar.
 *
 * O controller é magro (CLAUDE.md §4.3): traduz HTTP, chama **uma** action e
 * devolve Resource. A consulta é do `UserDirectory` (módulo Users) e a escrita é
 * do `ChangeUserStatusAction` (módulo Users) — Administration não constrói query
 * nem escreve em tabela alheia (ADR 0010 §2).
 */
final class AdminUserController
{
    /** `GET /admin/users` */
    public function index(Request $request, UserDirectory $directory): AnonymousResourceCollection
    {
        $filter = mb_strtolower((string) $request->string('filter'));

        $users = $directory->paginate(
            role: match ($filter) {
                'player' => Role::PLAYER,
                'organizer' => Role::ORGANIZER,
                default => null,
            },
            status: match ($filter) {
                'active' => UserStatus::ACTIVE,
                'blocked' => UserStatus::BLOCKED,
                default => null,
            },
            search: $request->filled('q') ? (string) $request->string('q') : null,
            onlyBothRoles: $filter === 'both',
            perPage: $request->integer('per_page', 25),
        );

        return AdminUserResource::collection($users);
    }

    /**
     * `POST /admin/users/{user}/block`
     *
     * Sub-recurso explícito, não `PATCH status` (§13): é uma ação de estado com
     * consequência e justificativa, não a edição de um campo.
     */
    public function block(
        AdministrativeReasonRequest $request,
        User $user,
        ChangeUserStatusAction $action,
    ): AdminUserResource {
        $actor = $this->actor($request);

        $action->execute($user, $actor, UserStatus::BLOCKED, $request->reason());

        return new AdminUserResource($user->load(['roles', 'organizer.plan']));
    }

    /** `POST /admin/users/{user}/unblock` */
    public function unblock(
        AdministrativeReasonRequest $request,
        User $user,
        ChangeUserStatusAction $action,
    ): AdminUserResource {
        $actor = $this->actor($request);

        $action->execute($user, $actor, UserStatus::ACTIVE, $request->reason());

        return new AdminUserResource($user->load(['roles', 'organizer.plan']));
    }

    /**
     * O ator é sempre a sessão, nunca um parâmetro (§10). O middleware
     * `EnsureSuperAdmin` já garantiu que existe e que é super admin — este
     * método existe para o PHPStan nível 8 provar o tipo.
     */
    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
