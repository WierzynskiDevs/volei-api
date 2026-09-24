<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application\Jobs;

use App\Modules\Notifications\Domain\Enums\NotificationDeliveryStatus;
use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envia uma notificação transacional já gravada (CLAUDE.md §20).
 *
 * `body` chega por parâmetro do job, não do banco — `notification_deliveries`
 * guarda `subject`/`metadata` sanitizados, nunca o corpo renderizado
 * (`NotificationDispatcher`). Falha final vai para `failed_jobs` e o registro
 * fica `FAILED` com o motivo, igual ao padrão de `ProcessWebhookEventJob`.
 */
final class SendNotificationEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 120, 300];

    public function __construct(
        public readonly string $notificationDeliveryId,
        public readonly string $body,
    ) {}

    public function handle(): void
    {
        $delivery = NotificationDelivery::query()->find($this->notificationDeliveryId);

        if (! $delivery instanceof NotificationDelivery) {
            return;
        }

        if ($delivery->status === NotificationDeliveryStatus::SENT) {
            return; // reentrega da fila, já enviado.
        }

        if ($delivery->recipient_email === null) {
            $delivery->status = NotificationDeliveryStatus::FAILED;
            $delivery->error_message = 'sem e-mail de destinatário';
            $delivery->save();

            return;
        }

        try {
            $subject = $delivery->subject;
            $body = $this->body;

            Mail::raw($body, function ($message) use ($delivery, $subject): void {
                $message->to($delivery->recipient_email)->subject($subject);
            });

            $delivery->status = NotificationDeliveryStatus::SENT;
            $delivery->sent_at = CarbonImmutable::now();
            $delivery->save();
        } catch (Throwable $e) {
            $delivery->status = NotificationDeliveryStatus::FAILED;
            $delivery->error_message = mb_substr($e->getMessage(), 0, 500);
            $delivery->save();

            Log::channel('audit')->error('Falha ao enviar notificação', [
                'notification_delivery_id' => $delivery->id,
                'type' => $delivery->type->value,
                'attempts' => $this->attempts(),
                'exception' => $e->getMessage(),
            ]);

            throw $e; // deixa o retry da fila agir
        }
    }
}
