<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Http\Policies;

use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Infrastructure\Models\User;

/**
 * Autorização de inscrição (CLAUDE.md §10, aditivo §26).
 *
 * Duas relações distintas dão acesso, e cada uma dá acesso diferente:
 *
 *  - **o próprio atleta** — vê e cancela a inscrição dele;
 *  - **o organizador do evento** — vê a lista e decide análise de nível.
 *
 * Ownership sempre por comparação de FK, nunca por parâmetro de URL: passar o id
 * da inscrição de outra pessoa não muda nada, que é exatamente o teste de IDOR
 * exigido em §22. O aditivo §26 pede a cadeia
 * `user → role → event → autorização → recurso` — aqui ela é
 * `user → organizers.id → events.organizer_id → registrations.event_id`.
 */
final class RegistrationPolicy
{
    /** Super admin tem acesso global, e a action que ele dispara é auditada. */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole(Role::SUPER_ADMIN) ? true : null;
    }

    /**
     * Inscrever-se não exige papel especial: qualquer conta ativa é atleta em
     * potencial. O bloqueio de conta é tratado no middleware de sessão, não aqui.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Registration $registration): bool
    {
        return $this->isOwnRegistration($user, $registration)
            || $this->organizesEventOf($user, $registration);
    }

    /**
     * Cancelar: o atleta desiste da própria inscrição; o organizador cancela
     * inscrição no evento dele (ex.: depois de reprovar o nível).
     */
    public function cancel(User $user, Registration $registration): bool
    {
        return $this->isOwnRegistration($user, $registration)
            || $this->organizesEventOf($user, $registration);
    }

    /**
     * Pagar é **só** do titular da inscrição.
     *
     * Nem o organizador paga pelo atleta: a cobrança é nominal ao pagador
     * (ADR 0001, `payments.user_id`), e deixar um terceiro criá-la produziria
     * cobrança no nome de quem não pediu.
     */
    public function pay(User $user, Registration $registration): bool
    {
        return $this->isOwnRegistration($user, $registration);
    }

    /**
     * Aceitar o convite de dupla é **só** do próprio convidado (Q14, ADR 0015) —
     * mesmo raciocínio de `pay()`: nem quem convidou, nem o organizador, aceita
     * em nome de outra pessoa.
     */
    public function accept(User $user, Registration $registration): bool
    {
        return $this->isOwnRegistration($user, $registration);
    }

    /**
     * Decidir análise de nível é **só** do organizador do evento.
     *
     * O atleta não aprova o próprio nível — seria o cliente decidindo uma regra
     * de negócio sobre si mesmo (CLAUDE.md §27.7).
     */
    public function reviewLevel(User $user, Registration $registration): bool
    {
        return $this->organizesEventOf($user, $registration);
    }

    private function isOwnRegistration(User $user, Registration $registration): bool
    {
        return $registration->user_id === $user->id;
    }

    /**
     * `loadMissing` é carregamento explícito, então convive com
     * `preventLazyLoading` ativo (CLAUDE.md §5).
     */
    private function organizesEventOf(User $user, Registration $registration): bool
    {
        $user->loadMissing('organizer');
        $organizerId = $user->organizer?->id;

        if ($organizerId === null) {
            return false;
        }

        $registration->loadMissing('event');
        $event = $registration->event;

        return $event !== null && $event->organizer_id === $organizerId;
    }
}
