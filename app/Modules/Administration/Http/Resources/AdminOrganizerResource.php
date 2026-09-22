<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Resources;

use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Organizers\Infrastructure\Models\OrganizerPlanHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Organizador na visão administrativa.
 *
 * `events_count` e `finance` **não** são colunas: vêm de `EventDirectory` e de
 * `PlatformFinanceReport`, e o controller os anexa ao model antes de serializar.
 * É o mesmo mecanismo do `withCount()` do Laravel, que também produz um
 * `events_count` que não existe na tabela — a diferença é que aqui o número vem
 * do módulo dono, e não de um JOIN cruzando fronteira (ADR 0010 §2).
 *
 * Os dois são lidos de forma defensiva: o endpoint de mudança de situação
 * devolve o mesmo Resource **sem** compor as agregações, e ali eles são `null` —
 * não zero, que afirmaria "nenhum evento" e "nenhum dinheiro".
 *
 * @mixin Organizer
 */
final class AdminOrganizerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'city' => $this->city,
            'state' => $this->state,
            'contact_email' => $this->contact_email,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_account_status' => $this->payment_account_status->value,
            'payment_account_status_label' => $this->payment_account_status->label(),
            'can_receive_payments' => $this->canReceivePayments(),

            'plan' => $this->whenLoaded('plan', fn (): ?array => $this->plan === null ? null : [
                'code' => $this->plan->code,
                'name' => $this->plan->name,
                // A taxa vigente do plano — NÃO é a taxa das cobranças já
                // emitidas, que ficou congelada em cada pagamento (§7.5).
                'platform_fee_basis_points' => $this->plan->platform_fee_basis_points,
            ]),

            'owner' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'status' => $this->user->status->value,
            ]),

            /*
             * `getAttributes()` e não `$this->events_count`:
             * `preventAccessingMissingAttributes()` está ativo fora de produção
             * (§5) e estouraria exceção quando a agregação não foi composta.
             */
            'events_count' => $this->resource->getAttributes()['events_count'] ?? null,
            'finance' => $this->resource->getAttributes()['finance'] ?? null,

            /*
             * Histórico de plano: só na tela de detalhe, que é quem o carrega.
             * `whenLoaded` evita que a listagem dispare N+1 para uma seção que
             * ela nem mostra (§26).
             */
            'plan_history' => $this->whenLoaded('planHistory', fn (): array => $this->planHistory
                ->map(fn (OrganizerPlanHistory $entry): array => [
                    'id' => $entry->id,
                    'plan_code' => $entry->plan?->code,
                    'plan_name' => $entry->plan?->name,
                    'platform_fee_basis_points' => $entry->platform_fee_basis_points,
                    'note' => $entry->note,
                    'effective_at' => $entry->effective_at?->toIso8601String(),
                ])
                ->values()
                ->all()),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
