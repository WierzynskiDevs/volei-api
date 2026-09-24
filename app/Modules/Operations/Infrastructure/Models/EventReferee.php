<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure\Models;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Domain\Enums\RefereeInviteStatus;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\EventRefereeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * Juiz convidado para um evento (ADR 0013 §5/§6/§8 — S8b).
 *
 * `HasApiTokens`: a sessão escopada por token (ADR 0013 §5) reaproveita a
 * infra do Sanctum já instalada (`personal_access_tokens`, sem uso até
 * agora) em vez de inventar mecanismo novo — o mesmo token polimórfico que o
 * pacote já suporta para qualquer model, não só `User`. Nunca passa pelo
 * guard `auth:sanctum` (isso continua exclusivo de `User`/cookie SPA,
 * ADR 0005) — resolvido à mão por `EnsureRefereeSession`.
 *
 * @property string $id
 * @property string $event_id
 * @property string|null $user_id
 * @property string|null $court_id
 * @property string $name
 * @property RefereeInviteStatus $invite_status
 * @property CarbonImmutable|null $invited_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class EventReferee extends Model
{
    use HasApiTokens;

    /** @use HasFactory<EventRefereeFactory> */
    use HasFactory;
    use HasUuids;

    protected $table = 'event_referees';

    /**
     * `invite_status`, `invited_at` e `accepted_at` ficam fora: são
     * consequência de ação (convidar, aceitar), nunca de formulário direto.
     */
    protected $fillable = [
        'event_id',
        'user_id',
        'court_id',
        'name',
        'phone_encrypted',
        'phone_hash',
    ];

    /** @var list<string> */
    protected $hidden = [
        'phone_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'phone_encrypted' => 'encrypted',
            'invite_status' => RefereeInviteStatus::class,
            'invited_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): EventRefereeFactory
    {
        return EventRefereeFactory::new();
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Court, $this> */
    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    /** @return HasMany<RefereeInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(RefereeInvitation::class);
    }
}
