<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Domain\Exceptions\DuplicateRefereePhoneException;
use App\Modules\Operations\Domain\Services\RefereePhoneProtector;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Users\Infrastructure\Models\User;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cadastra um juiz no evento, ainda sem convite (ADR 0013 §5/§8 — S8b).
 *
 * Consumidor: `InviteRefereeDialog` → botão "Gerar link" em
 * `/organizador/controle` (hoje mock, `addReferee()` de `lib/operations.tsx`).
 * Nasce em `NOT_SENT` — o convite é um passo separado (`InviteRefereeAction`),
 * igual ao mock já modela.
 */
final readonly class AddRefereeAction
{
    public function __construct(
        private AuditLogger $audit,
        private RefereePhoneProtector $phones,
    ) {}

    public function execute(Event $event, User $actor, string $name, string $phone): EventReferee
    {
        if (! $this->isValidPhone($phone)) {
            // FormRequest já valida forma; chegar aqui inválido é bypass da
            // camada HTTP — falha alto, não silencioso.
            throw new RuntimeException('Telefone inválido chegou à action sem passar pela validação de forma.');
        }

        $hash = $this->phones->hash($phone);

        if (EventReferee::query()->where('event_id', $event->id)->where('phone_hash', $hash)->exists()) {
            throw DuplicateRefereePhoneException::create();
        }

        $referee = EventReferee::query()->create([
            'event_id' => $event->id,
            'name' => $name,
            'phone_encrypted' => $this->phones->normalize($phone),
            'phone_hash' => $hash,
        ]);

        // `invite_status` tem default só no banco (fora de $fillable de
        // propósito) — sem isto, o model em memória fica com o atributo
        // `null` até a próxima leitura, e o Resource quebra.
        $referee->refresh();

        $this->audit->log(
            action: AuditAction::REFEREE_ADDED,
            actor: $actor,
            targetType: 'event_referee',
            targetId: $referee->id,
            metadata: [
                'event_id' => $event->id,
                // Nunca o telefone em claro na auditoria (CLAUDE.md §9/§21).
            ],
        );

        return $referee;
    }

    private function isValidPhone(string $phone): bool
    {
        try {
            $this->phones->normalize($phone);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
