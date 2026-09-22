<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure\Models;

use App\Modules\Events\Domain\Enums\AgeCategory;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Domain\Enums\EventType;
use App\Modules\Events\Domain\Enums\GenderCategory;
use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Events\Domain\Enums\Modality;
use App\Modules\Events\Domain\Enums\RegistrationFieldKey;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Evento (BRIEF §17).
 *
 * Model é persistência (CLAUDE.md §4.3): casts, relações e scopes triviais.
 * Regra de negócio vive nas actions e no domínio — não existe "fat model" aqui.
 *
 * @property string $id
 * @property string $organizer_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $venue_name
 * @property string $city
 * @property string $state
 * @property CarbonImmutable $start_at
 * @property CarbonImmutable $end_at
 * @property string $timezone
 * @property CarbonImmutable|null $registration_open_at
 * @property CarbonImmutable|null $registration_close_at
 * @property int $registration_fee_cents
 * @property int|null $max_teams
 * @property int $courts
 * @property int $min_games
 * @property string|null $format
 * @property Modality $modality
 * @property GenderCategory $gender_category
 * @property LevelCategory $level_category
 * @property AgeCategory $age_category
 * @property EventType $event_type
 * @property string|null $prize_description
 * @property array<int, string> $rules
 * @property array<int, string> $required_registration_fields
 * @property array<int, string> $optional_registration_fields
 * @property int $days_count
 * @property int $match_duration_min
 * @property int $best_of_sets
 * @property int $points_per_set
 * @property int $tiebreak_points
 * @property array{win: int, loss: int, champion: int, runnerUp: int, third: int, participation: int} $scoring_rules
 * @property int $teams_registered_count
 * @property EventStatus $status
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'events';

    /**
     * Allowlist explícita (CLAUDE.md §5).
     *
     * Ficam de fora de propósito, porque são consequência de ação e não de
     * formulário: `status`, `published_at`, `cancelled_at`,
     * `cancellation_reason`, `teams_registered_count` e `organizer_id`.
     * Um update de perfil de evento jamais deve conseguir mudar dono, estado
     * ou contagem de vagas.
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'venue_name',
        'city',
        'state',
        'start_at',
        'end_at',
        'timezone',
        'registration_open_at',
        'registration_close_at',
        'registration_fee_cents',
        'max_teams',
        'courts',
        'min_games',
        'format',
        'modality',
        'gender_category',
        'level_category',
        'age_category',
        'event_type',
        'prize_description',
        'rules',
        'required_registration_fields',
        'optional_registration_fields',
        'days_count',
        'match_duration_min',
        'best_of_sets',
        'points_per_set',
        'tiebreak_points',
        'scoring_rules',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'registration_open_at' => 'immutable_datetime',
            'registration_close_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'registration_fee_cents' => 'integer',
            'max_teams' => 'integer',
            'courts' => 'integer',
            'min_games' => 'integer',
            'teams_registered_count' => 'integer',
            'rules' => 'array',
            'required_registration_fields' => 'array',
            'optional_registration_fields' => 'array',
            'days_count' => 'integer',
            'match_duration_min' => 'integer',
            'best_of_sets' => 'integer',
            'points_per_set' => 'integer',
            'tiebreak_points' => 'integer',
            'scoring_rules' => 'array',
            'status' => EventStatus::class,
            'modality' => Modality::class,
            'gender_category' => GenderCategory::class,
            'level_category' => LevelCategory::class,
            'age_category' => AgeCategory::class,
            'event_type' => EventType::class,
        ];
    }

    /** Namespace modular quebra a resolução automática de fábrica. */
    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }

    /** @return BelongsTo<Organizer, $this> */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    /** Valor da inscrição como value object. Nunca float (CLAUDE.md §7). */
    public function registrationFee(): Money
    {
        return Money::fromCents($this->registration_fee_cents);
    }

    public function isFree(): bool
    {
        return $this->registration_fee_cents === 0;
    }

    /**
     * Campos de inscrição que este evento exige (Q19, ADR 0016).
     *
     * @return list<RegistrationFieldKey>
     */
    public function requiredRegistrationFieldKeys(): array
    {
        return array_values(array_map(
            fn (string $v): RegistrationFieldKey => RegistrationFieldKey::from($v),
            $this->required_registration_fields ?? [],
        ));
    }

    /** Campo exigido OU oferecido como opcional — em qualquer um dos dois, a inscrição pode responder. */
    public function collectsRegistrationField(RegistrationFieldKey $field): bool
    {
        return in_array($field->value, $this->required_registration_fields ?? [], strict: true)
            || in_array($field->value, $this->optional_registration_fields ?? [], strict: true);
    }

    /**
     * Vagas restantes. `null` quando o evento não tem teto — o que é diferente
     * de zero e não pode virar zero por descuido (docs/DIVERGENCES.md §6).
     */
    public function remainingTeamSlots(): ?int
    {
        if ($this->max_teams === null) {
            return null;
        }

        return max(0, $this->max_teams - $this->teams_registered_count);
    }

    /**
     * Vitrine pública: o que aparece em `/eventos`.
     *
     * A lista de estados é derivada do enum, não repetida aqui — duas listas
     * divergem no dia em que um estado novo entra.
     *
     * @param  Builder<Event>  $query
     */
    public function scopePubliclyListed(Builder $query): void
    {
        $query->whereIn('status', array_map(
            fn (EventStatus $status): string => $status->value,
            array_filter(EventStatus::cases(), fn (EventStatus $s): bool => $s->isListedPublicly()),
        ));
    }

    /**
     * Tudo que tem página pública — inclui cancelado e finalizado, que
     * continuam acessíveis por link direto. Exclui apenas o rascunho.
     *
     * @param  Builder<Event>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where('status', '!=', EventStatus::DRAFT->value);
    }
}
