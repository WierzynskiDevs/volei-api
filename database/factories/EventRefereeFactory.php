<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Domain\Enums\RefereeInviteStatus;
use App\Modules\Operations\Domain\Services\RefereePhoneProtector;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventReferee>
 */
final class EventRefereeFactory extends Factory
{
    protected $model = EventReferee::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $phone = '4199'.fake()->unique()->numerify('#######');

        return [
            'event_id' => Event::factory(),
            'user_id' => null,
            'court_id' => null,
            'name' => fake()->name(),
            'phone_encrypted' => $phone,
            'phone_hash' => app(RefereePhoneProtector::class)->hash($phone),
            'invite_status' => RefereeInviteStatus::NOT_SENT,
        ];
    }

    public function forEvent(Event $event): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_id' => $event->id,
        ]);
    }

    public function invited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'invite_status' => RefereeInviteStatus::SENT,
            'invited_at' => now(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'invite_status' => RefereeInviteStatus::ACCEPTED,
            'invited_at' => now()->subHour(),
            'accepted_at' => now(),
        ]);
    }
}
