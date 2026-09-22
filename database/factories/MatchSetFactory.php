<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Operations\Infrastructure\Models\MatchSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchSet>
 */
final class MatchSetFactory extends Factory
{
    protected $model = MatchSet::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'match_id' => GameMatch::factory(),
            'set_number' => 1,
            'score_a' => 21,
            'score_b' => 18,
        ];
    }

    public function forMatch(GameMatch $match): static
    {
        return $this->state(fn (array $attributes): array => ['match_id' => $match->id]);
    }
}
