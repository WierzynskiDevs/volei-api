<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure\Models;

use App\Modules\Events\Infrastructure\Models\Event;
use Carbon\CarbonImmutable;
use Database\Factories\CourtFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quadra do evento (ADR 0013 §2/§8 — S8a).
 *
 * `Event` não tem relação reversa `courts()` de propósito: o módulo dono
 * (`Events`) não conhece os módulos satélite que dependem dele
 * (CLAUDE.md §4.1) — o mesmo motivo pelo qual `Event` também não tem
 * `registrations()` nem `payments()`. Quem precisa das quadras de um evento
 * consulta por `event_id`, dentro do módulo `Operations`.
 *
 * @property string $id
 * @property string $event_id
 * @property string $label
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Court extends Model
{
    /** @use HasFactory<CourtFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'courts';

    protected $fillable = [
        'event_id',
        'label',
        'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    protected static function newFactory(): CourtFactory
    {
        return CourtFactory::new();
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
