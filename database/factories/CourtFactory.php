<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Infrastructure\Models\Court;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Court>
 */
final class CourtFactory extends Factory
{
    protected $model = Court::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'label' => 'Quadra '.fake()->unique()->numberBetween(1, 999),
            'position' => 0,
        ];
    }

    public function forEvent(Event $event): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_id' => $event->id,
        ]);
    }
}
