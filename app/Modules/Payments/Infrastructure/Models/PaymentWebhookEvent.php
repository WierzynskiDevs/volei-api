<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Models;

use App\Modules\Payments\Domain\Enums\WebhookProcessingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento de webhook recebido do gateway (CLAUDE.md §14).
 *
 * Gravado **antes** de qualquer processamento, com unique em
 * `(provider, provider_event_id)`. A entrega do Asaas é "at least once" — o
 * mesmo evento chega repetido, e é este registro que absorve a repetição.
 *
 * `$timestamps` fica ligado porque `processing_status` muda; o que não muda é a
 * existência da linha (trigger recusa delete).
 *
 * @property string $id
 * @property string $provider
 * @property string $provider_event_id
 * @property string $provider_event_type
 * @property string|null $provider_payment_id
 * @property string|null $payment_id
 * @property array<string, mixed> $payload
 * @property WebhookProcessingStatus $processing_status
 * @property string|null $error_message
 * @property int $attempts
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 */
final class PaymentWebhookEvent extends Model
{
    use HasUuids;

    protected $table = 'payment_webhook_events';

    protected $fillable = [
        'provider',
        'provider_event_id',
        'provider_event_type',
        'provider_payment_id',
        'payment_id',
        'payload',
        'received_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processing_status' => WebhookProcessingStatus::class,
            'attempts' => 'integer',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
