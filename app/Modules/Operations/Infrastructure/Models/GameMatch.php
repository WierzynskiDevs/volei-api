<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure\Models;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Domain\Enums\MatchStatus;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\GameMatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Partida entre duas duplas (ADR 0013 §2/§3/§4/§9 — S9, o núcleo do
 * operacional).
 *
 * Classe `GameMatch`, não `Match`: `match` é palavra reservada da linguagem
 * desde o PHP 8.0 (expressão `match`) — `class Match {}` nem chega a fazer
 * parse. A tabela continua `matches`.
 *
 * `team_a_id`/`team_b_id` apontam para `RegistrationGroup` (módulo
 * `Registrations`) — mesmo padrão de `Court`/`EventReferee` referenciando
 * `Event`: leitura via relação Eloquent atravessa módulo, mas a ESCRITA
 * (elegibilidade da dupla) só acontece pela action (CLAUDE.md §4.1).
 *
 * @property string $id
 * @property string $event_id
 * @property string|null $court_id
 * @property string|null $referee_id
 * @property string $team_a_id
 * @property string $team_b_id
 * @property string|null $phase
 * @property MatchStatus $status
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $started_by
 * @property string|null $finished_by
 * @property string|null $start_idempotency_key
 * @property string|null $finish_idempotency_key
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class GameMatch extends Model
{
    /** @use HasFactory<GameMatchFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'matches';

    /**
     * `status`, `started_at`/`finished_at`/`started_by`/`finished_by` ficam
     * fora: são consequência de ação (CLAUDE.md §5), nunca de formulário.
     */
    protected $fillable = [
        'event_id',
        'court_id',
        'referee_id',
        'team_a_id',
        'team_b_id',
        'phase',
        'scheduled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MatchStatus::class,
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): GameMatchFactory
    {
        return GameMatchFactory::new();
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Court, $this> */
    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    /** @return BelongsTo<EventReferee, $this> */
    public function referee(): BelongsTo
    {
        return $this->belongsTo(EventReferee::class);
    }

    /** @return BelongsTo<RegistrationGroup, $this> */
    public function teamA(): BelongsTo
    {
        return $this->belongsTo(RegistrationGroup::class, 'team_a_id');
    }

    /** @return BelongsTo<RegistrationGroup, $this> */
    public function teamB(): BelongsTo
    {
        return $this->belongsTo(RegistrationGroup::class, 'team_b_id');
    }

    /** @return BelongsTo<User, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** @return BelongsTo<User, $this> */
    public function finishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finished_by');
    }

    /**
     * @return HasMany<MatchSet, $this>
     *
     * FK explícito: o padrão do Eloquent derivaria `game_match_id` do nome da
     * classe `GameMatch`, mas a coluna real é `match_id` (§ comentário da
     * classe — `match` é palavra reservada, a classe não podia se chamar
     * igual à tabela/coluna).
     */
    public function sets(): HasMany
    {
        return $this->hasMany(MatchSet::class, 'match_id');
    }
}
