<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Resources;

use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Domain\Services\PhoneProtector;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Usuário na visão administrativa.
 *
 * ## Por que não reusar `UserResource`
 *
 * `UserResource` entrega telefone e data de nascimento **completos** ao super
 * admin, o que é correto na consulta de um titular específico. Numa **listagem**
 * é minimização ao contrário: despejaria o telefone de toda a base numa
 * resposta, para preencher uma coluna que a tela já mostra mascarada
 * ("Dados de contato ficam mascarados por padrão", texto de `/admin/usuarios`).
 *
 * Então aqui: e-mail sim — é como o admin identifica a conta e já aparece na
 * tela; telefone **só mascarado**, e a máscara é feita no servidor (§12: não se
 * confia no cliente para mascarar).
 *
 * @mixin User
 */
final class AdminUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone_masked' => $this->phone_encrypted === null
                ? null
                : app(PhoneProtector::class)->mask((string) $this->phone_encrypted),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'level' => $this->level?->value,
            'city' => $this->city,
            'state' => $this->state,

            'roles' => $this->whenLoaded(
                'roles',
                fn (): array => array_map(fn (Role $role): string => $role->value, $this->roleList()),
            ),

            /*
             * O vínculo de organizador entra na própria linha porque a tela
             * distingue "Jogador", "Organizador" e "Ambos" — e a coluna de ação
             * muda conforme o caso.
             */
            'organizer' => $this->whenLoaded('organizer', fn (): ?array => $this->organizer === null ? null : [
                'id' => $this->organizer->id,
                'name' => $this->organizer->name,
                'status' => $this->organizer->status->value,
                'plan_code' => $this->organizer->plan?->code,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
