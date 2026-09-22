<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Http\Resources;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representação de uma inscrição.
 *
 * CLAUDE.md §13: campos explícitos, nunca o model direto.
 *
 * LGPD (§12): o organizador tem direito de ver **quem** se inscreveu — nome é
 * necessário para operar o campeonato e a tela já mostra a coluna "Jogador".
 * E-mail e telefone **não** entram aqui: o organizador não precisa deles para
 * montar chave nem para conferir pagamento, e "o backend não envia o dado
 * completo quando o solicitante não tem direito a ele".
 *
 * @mixin Registration
 */
final class RegistrationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'user_id' => $this->user_id,
            'group_id' => $this->group_id,
            'is_captain' => $this->is_captain,
            'partner_mode' => $this->partner_mode->value,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // Análise de nível (aditivo §17). `player_level` e `event_level` são
            // o snapshot do momento da inscrição, não o valor atual do perfil.
            'level_review' => $this->level_review->value,
            'level_review_label' => $this->level_review->label(),
            'player_level' => $this->player_level?->value,
            'event_level' => $this->event_level->value,
            'level_review_decided_at' => $this->level_review_decided_at?->toIso8601String(),
            'level_review_reason' => $this->level_review_reason,

            'reserved_until' => $this->reserved_until?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),

            /*
             * Q19/ADR 0016 — só o que o evento pediu chega aqui (a action já
             * garante isso). Visível só a quem a Policy já autorizou a ver a
             * inscrição inteira (o próprio atleta ou o organizador do evento) —
             * este Resource não adiciona uma segunda checagem, a rota já fez.
             */
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone_encrypted,
            'shirt_size' => $this->shirt_size?->value,
            'team_name' => $this->team_name,
            'dietary_restriction' => $this->dietary_restriction,

            /*
             * Só o nome do atleta. A tela `/organizador/inscricoes` mostra
             * "Jogador" e "Dupla" — nada além disso é necessário para operar.
             */
            'player' => $this->whenLoaded('user', fn (): ?array => $this->playerPayload()),

            'group' => $this->whenLoaded('group', fn (): ?array => $this->groupPayload()),

            'event' => $this->whenLoaded('event', fn (): ?array => $this->eventPayload()),

            /*
             * Cobrança mais recente, anexada pelo controller — não é relação
             * deste model: `Registrations` não conhece a tabela `payments`
             * (§4.1). `null` significa "nenhuma cobrança criada ainda", que é
             * diferente de "cobrança de R$ 0,00".
             */
            'payment' => $this->paymentPayload(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function playerPayload(): ?array
    {
        $user = $this->user;

        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'level' => $user->level?->value,
        ];
    }

    /**
     * Resumo da cobrança, quando o controller a compôs.
     *
     * Lido de `getAttributes()` porque `latest_payment` não é coluna e
     * `preventAccessingMissingAttributes()` está ativo fora de produção (§5).
     *
     * Só o necessário para a tela do atleta: quanto, como, em que estado e
     * até quando. Taxa da plataforma e líquido do organizador **não** entram —
     * não são assunto de quem paga.
     *
     * @return array<string, mixed>|null
     */
    private function paymentPayload(): ?array
    {
        $payment = $this->resource->getAttributes()['latest_payment'] ?? null;

        if (! $payment instanceof Payment) {
            return null;
        }

        return [
            'id' => $payment->id,
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'method' => $payment->method->value,
            'method_label' => $payment->method->label(),
            'gross_cents' => $payment->gross_cents,
            'confirms_registration' => $payment->status->confirmsRegistration(),
            'due_at' => $payment->due_at->toIso8601String(),
            'confirmed_at' => $payment->confirmed_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function eventPayload(): ?array
    {
        $event = $this->event;

        if (! $event instanceof Event) {
            return null;
        }

        return [
            'id' => $event->id,
            'slug' => $event->slug,
            'name' => $event->name,
            'start_at' => $event->start_at->toIso8601String(),
            'registration_fee_cents' => $event->registration_fee_cents,
        ];
    }

    /** @return array<string, mixed>|null */
    private function groupPayload(): ?array
    {
        $group = $this->group;

        if (! $group instanceof RegistrationGroup) {
            return null;
        }

        return [
            'id' => $group->id,
            'display_name' => $group->displayName(),
            'status' => $group->status->value,
            'status_label' => $group->status->label(),
            'members' => $group->relationLoaded('registrations')
                ? $group->registrations
                    ->map(fn (Registration $r): array => [
                        'registration_id' => $r->id,
                        'name' => $r->relationLoaded('user') ? $r->user?->name : null,
                        'status' => $r->status->value,
                        'is_captain' => $r->is_captain,
                    ])
                    ->values()
                    ->all()
                : null,
        ];
    }
}
