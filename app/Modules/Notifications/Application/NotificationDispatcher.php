<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Application\Jobs\SendNotificationEmailJob;
use App\Modules\Notifications\Domain\Enums\NotificationChannel;
use App\Modules\Notifications\Domain\Enums\NotificationDeliveryStatus;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use App\Modules\Users\Infrastructure\Models\User;

/**
 * Único ponto de entrada do módulo `Notifications` (ADR 0010 §2: outro
 * módulo escreve por aqui, nunca `NotificationDelivery::create()` direto).
 *
 * Grava o registro (`PENDING`) e enfileira o envio — a request HTTP nunca
 * espera o e-mail sair (CLAUDE.md §20). `body` vira `metadata` sanitizado, não
 * o corpo completo do e-mail: o que interessa auditar é o quê/quem/quando, não
 * o texto renderizado inteiro (mesmo espírito do §9 aplicado aqui).
 */
final readonly class NotificationDispatcher
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function queueEmail(
        NotificationType $type,
        User $recipient,
        string $subject,
        string $body,
        array $metadata = [],
    ): void {
        if ($recipient->email === null || $recipient->email === '') {
            return;
        }

        $delivery = NotificationDelivery::query()->create([
            'type' => $type,
            'channel' => NotificationChannel::EMAIL,
            'status' => NotificationDeliveryStatus::PENDING,
            'recipient_user_id' => $recipient->id,
            'recipient_email' => $recipient->email,
            'subject' => $subject,
            'metadata' => $metadata,
        ]);

        SendNotificationEmailJob::dispatch($delivery->id, $body);
    }
}
