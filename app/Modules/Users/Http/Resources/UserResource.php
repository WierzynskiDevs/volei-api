<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Resources;

use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Domain\Services\PhoneProtector;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representação pública de um usuário.
 *
 * CLAUDE.md §13: nunca retornar o model direto — uma coluna nova vazaria sem
 * revisão. Aqui a lista de campos é explícita.
 *
 * CLAUDE.md §12: o telefone completo só vai para o próprio titular ou para o
 * super admin. Para os demais, sequer é enviado — mascarar no cliente não
 * protege nada.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * `mergeWhen` injeta MergeValue com chave numérica, então o array não é
     * estritamente array<string, mixed> antes da serialização.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isSelf = $viewer !== null && $viewer->id === $this->id;
        $isAdmin = $viewer?->isSuperAdmin() ?? false;
        $canSeePersonalData = $isSelf || $isAdmin;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar_url' => $this->avatar_path,
            // `->value` explícito: `level` virou enum na migration 2026_08_26_000100
            // e o contrato da API é a string do enum, não o objeto.
            'level' => $this->level?->value,
            'city' => $this->city,
            'state' => $this->state,
            'status' => $this->status->value,

            // Papéis vêm do banco. É esta lista que o frontend usa para navegar —
            // mas quem autoriza de fato é a policy no backend (CLAUDE.md §10).
            'roles' => $this->whenLoaded(
                'roles',
                fn (): array => array_map(
                    fn (Role $role): string => $role->value,
                    $this->roleList(),
                ),
            ),

            'organizer' => $this->whenLoaded('organizer', fn (): ?array => $this->organizer === null ? null : [
                'id' => $this->organizer->id,
                'name' => $this->organizer->name,
                'slug' => $this->organizer->slug,
                'status' => $this->organizer->status->value,
                'payment_account_status' => $this->organizer->payment_account_status->value,
            ]),

            // Dados pessoais: só para quem tem direito.
            $this->mergeWhen($canSeePersonalData, fn (): array => [
                'email' => $this->email,
                'phone' => $this->phone_encrypted,
                'birth_date' => $this->birth_date?->toDateString(),
                'terms_accepted_at' => $this->terms_accepted_at?->toIso8601String(),
                'privacy_accepted_at' => $this->privacy_accepted_at?->toIso8601String(),
            ]),

            // Para terceiros, apenas o suficiente para identificação visual.
            $this->mergeWhen(! $canSeePersonalData && $this->phone_encrypted !== null, fn (): array => [
                'phone_masked' => app(PhoneProtector::class)->mask((string) $this->phone_encrypted),
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
