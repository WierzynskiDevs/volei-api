<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Infrastructure\Models;

use App\Modules\Notifications\Domain\Enums\NotificationChannel;
use App\Modules\Notifications\Domain\Enums\NotificationDeliveryStatus;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrega de notificação transacional (CLAUDE.md §20, B4). Muda de status
 * (PENDING → SENT/FAILED) — não é `audit_logs`, não é append-only.
 *
 * @property string $id
 * @property NotificationType $type
 * @property NotificationChannel $channel
 * @property NotificationDeliveryStatus $status
 * @property string|null $recipient_user_id
 * @property string|null $recipient_email
 * @property string $subject
 * @property array<string, mixed>|null $metadata
 * @property string|null $error_message
 * @property CarbonImmutable|null $sent_at
 */
final class NotificationDelivery extends Model
{
    use HasUuids;

    protected $table = 'notification_deliveries';

    protected $fillable = [
        'type',
        'channel',
        'status',
        'recipient_user_id',
        'recipient_email',
        'subject',
        'metadata',
        'error_message',
        'sent_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'channel' => NotificationChannel::class,
            'status' => NotificationDeliveryStatus::class,
            'metadata' => 'array',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
