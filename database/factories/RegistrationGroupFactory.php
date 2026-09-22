<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Registrations\Domain\Enums\GroupStatus;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationGroup>
 */
final class RegistrationGroupFactory extends Factory
{
    protected $model = RegistrationGroup::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            // NULL de propósito: o nome padrão é derivado dos membros, e é esse
            // caminho que o teste precisa exercitar.
            'display_name' => null,
            'status' => GroupStatus::FORMING,
        ];
    }

    public function forEvent(Event $event): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_id' => $event->id,
        ]);
    }

    public function complete(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GroupStatus::COMPLETE,
        ]);
    }
}
