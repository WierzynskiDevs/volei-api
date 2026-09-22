<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Infrastructure\Models;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma posição da chave eliminatória inicial (ADR 0011).
 *
 * Model é persistência (CLAUDE.md §4.3): sem regra de negócio aqui — swap,
 * sorteio e publicação vivem nas actions de `Brackets\Application`.
 *
 * @property string $id
 * @property string $event_id
 * @property int $position
 * @property string|null $registration_group_id
 * @property bool $is_bye
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class BracketSlot extends Model
{
    use HasUuids;

    protected $table = 'bracket_slots';

    protected $fillable = [
        'event_id',
        'position',
        'registration_group_id',
        'is_bye',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_bye' => 'boolean',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<RegistrationGroup, $this> */
    public function registrationGroup(): BelongsTo
    {
        return $this->belongsTo(RegistrationGroup::class);
    }
}
