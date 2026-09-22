<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MatchSetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resultado de UM set (ADR 0013 §3/§4 — S9). Nunca ponto a ponto.
 *
 * @property string $id
 * @property string $match_id
 * @property int $set_number
 * @property int $score_a
 * @property int $score_b
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class MatchSet extends Model
{
    /** @use HasFactory<MatchSetFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'match_sets';

    protected $fillable = [
        'match_id',
        'set_number',
        'score_a',
        'score_b',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'set_number' => 'integer',
            'score_a' => 'integer',
            'score_b' => 'integer',
        ];
    }

    protected static function newFactory(): MatchSetFactory
    {
        return MatchSetFactory::new();
    }

    /** @return BelongsTo<GameMatch, $this> */
    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class);
    }
}
