<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token de convite do juiz (ADR 0013 §5 — S8b).
 *
 * Só o hash do token vive aqui — o valor bruto nunca é persistido, mesmo
 * padrão de token de reset de senha (ADR 0005).
 *
 * @property string $id
 * @property string $event_referee_id
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable $created_at
 */
final class RefereeInvitation extends Model
{
    use HasUuids;

    protected $table = 'referee_invitations';

    public $timestamps = false;

    protected $fillable = [
        'event_referee_id',
        'token_hash',
        'expires_at',
        'used_at',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<EventReferee, $this> */
    public function referee(): BelongsTo
    {
        return $this->belongsTo(EventReferee::class, 'event_referee_id');
    }
}
