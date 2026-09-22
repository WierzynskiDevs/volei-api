<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Infrastructure\Models;

use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Registrations\Domain\Enums\LevelReview;
use App\Modules\Registrations\Domain\Enums\PartnerMode;
use App\Modules\Registrations\Domain\Enums\RegistrationStatus;
use App\Modules\Registrations\Domain\Enums\ShirtSize;
use App\Modules\Users\Domain\Enums\PlayerLevel;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\RegistrationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inscrição de UM atleta num evento (ADR 0001).
 *
 * Sem soft delete de propósito: ADR 0003 determina que reserva expirada vai para
 * estado terminal auditável, não desaparece. E o aditivo §18 exige que inscrição
 * reprovada continue no histórico.
 *
 * @property string $id
 * @property string $event_id
 * @property string $user_id
 * @property string|null $group_id
 * @property bool $is_captain
 * @property PartnerMode $partner_mode
 * @property RegistrationStatus $status
 * @property LevelReview $level_review
 * @property PlayerLevel|null $player_level
 * @property LevelCategory $event_level
 * @property string|null $level_review_decided_by
 * @property CarbonImmutable|null $level_review_decided_at
 * @property string|null $level_review_reason
 * @property CarbonImmutable|null $reserved_until
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property string|null $accepted_rules_version
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone_encrypted
 * @property ShirtSize|null $shirt_size
 * @property string|null $team_name
 * @property string|null $dietary_restriction
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Registration extends Model
{
    /** @use HasFactory<RegistrationFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'registrations';

    /**
     * Allowlist explícita (CLAUDE.md §5).
     *
     * Ficam de fora porque são consequência de ação, nunca de formulário:
     * `status`, `level_review`, `confirmed_at`, `cancelled_at`,
     * `reserved_until` e todo o bloco de decisão de nível. Um atleta não
     * confirma a própria inscrição nem aprova o próprio nível.
     */
    protected $fillable = [
        'event_id',
        'user_id',
        'group_id',
        'is_captain',
        'partner_mode',
        'player_level',
        'event_level',
        'accepted_rules_version',
        // Q19/ADR 0016 — só a action grava, e só o que o evento realmente
        // coleta (Event::collectsRegistrationField()); fillable de propósito
        // porque o valor vem do atleta, diferente de status/confirmed_at.
        'emergency_contact_name',
        'emergency_contact_phone_encrypted',
        'shirt_size',
        'team_name',
        'dietary_restriction',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_captain' => 'boolean',
            'partner_mode' => PartnerMode::class,
            'status' => RegistrationStatus::class,
            'level_review' => LevelReview::class,
            'player_level' => PlayerLevel::class,
            'event_level' => LevelCategory::class,
            'level_review_decided_at' => 'immutable_datetime',
            'reserved_until' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'emergency_contact_phone_encrypted' => 'encrypted',
            'shirt_size' => ShirtSize::class,
        ];
    }

    protected static function newFactory(): RegistrationFactory
    {
        return RegistrationFactory::new();
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

    /** @return BelongsTo<RegistrationGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(RegistrationGroup::class, 'group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function levelReviewDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'level_review_decided_by');
    }

    /**
     * A reserva desta inscrição ainda vale?
     *
     * `null` em `reserved_until` significa "sem prazo" — o caso de inscrição
     * gratuita, que confirma na hora e não passa por reserva.
     */
    public function reservationIsActive(CarbonImmutable $now): bool
    {
        return $this->reserved_until === null || $this->reserved_until->greaterThan($now);
    }

    /**
     * Ocupa vaga agora? Cruza o estado com o vencimento da reserva (ADR 0003).
     */
    public function occupiesSlot(CarbonImmutable $now): bool
    {
        return $this->status->occupiesSlot() && $this->reservationIsActive($now);
    }

    /**
     * Inscrições que ocupam vaga, para a contagem sob lock.
     *
     * A lista de estados vem do enum, e o vencimento entra na mesma cláusula:
     * contar fora da transação seria contar um número que já mudou
     * (CLAUDE.md §8).
     *
     * @param  Builder<Registration>  $query
     */
    public function scopeOccupying(Builder $query, CarbonImmutable $now): void
    {
        $query
            ->whereIn('status', RegistrationStatus::occupyingValues())
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('reserved_until')->orWhere('reserved_until', '>', $now);
            });
    }

    /** @param  Builder<Registration>  $query */
    public function scopePendingLevelReview(Builder $query): void
    {
        $query->where('level_review', LevelReview::REQUIRED->value);
    }
}
