<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Policies;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Infrastructure\Models\User;

/**
 * Autorização de evento (CLAUDE.md §10).
 *
 * Ownership é verificado por **comparação de FK**, nunca por parâmetro vindo do
 * cliente: o organizador logado tem um `organizers.id`, e é esse valor que
 * precisa bater com `events.organizer_id`. Passar o id de outro organizador na
 * URL não muda nada — é exatamente o teste de IDOR exigido em §22.
 *
 * Super admin tem acesso global, e todo uso desse acesso é auditado pela action
 * que ele dispara.
 */
final class EventPolicy
{
    /**
     * Super admin passa por cima de tudo. Retornar `null` (em vez de `false`)
     * é o que permite que as demais regras continuem sendo avaliadas para
     * quem não é admin.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole(Role::SUPER_ADMIN) ? true : null;
    }

    /** Ver a própria lista de eventos exige ser organizador. */
    public function viewAny(User $user): bool
    {
        return $this->organizerIdOf($user) !== null;
    }

    public function view(User $user, Event $event): bool
    {
        return $this->owns($user, $event);
    }

    public function create(User $user): bool
    {
        return $this->organizerIdOf($user) !== null;
    }

    public function update(User $user, Event $event): bool
    {
        return $this->owns($user, $event);
    }

    /** Publicar, abrir/encerrar inscrições, cancelar. */
    public function changeStatus(User $user, Event $event): bool
    {
        return $this->owns($user, $event);
    }

    private function owns(User $user, Event $event): bool
    {
        $organizerId = $this->organizerIdOf($user);

        return $organizerId !== null && $organizerId === $event->organizer_id;
    }

    /**
     * `loadMissing` é carregamento explícito — não é lazy load, então convive
     * com `preventLazyLoading` ativo (CLAUDE.md §5).
     */
    private function organizerIdOf(User $user): ?string
    {
        $user->loadMissing('organizer');

        return $user->organizer?->id;
    }
}
