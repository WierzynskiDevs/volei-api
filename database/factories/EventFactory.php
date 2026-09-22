<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Events\Domain\Enums\AgeCategory;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Domain\Enums\EventType;
use App\Modules\Events\Domain\Enums\GenderCategory;
use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Events\Domain\Enums\Modality;
use App\Modules\Events\Domain\EventTimezone;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
final class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Copa '.fake()->city();

        /*
         * Horário construído no fuso do evento e convertido para UTC — o mesmo
         * caminho da action (ADR 0006). Fábrica que gravasse UTC "na mão"
         * validaria uma conversão que o sistema não faz.
         */
        $tz = EventTimezone::default();
        $date = CarbonImmutable::now($tz->toDateTimeZone())->addWeeks(2)->format('Y-m-d');

        return [
            'organizer_id' => Organizer::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => null,
            'venue_name' => 'Arena '.fake()->lastName(),
            'city' => fake()->city(),
            'state' => fake()->randomElement(['SC', 'PR', 'SP', 'PE', 'RN', 'ES']),
            'timezone' => $tz->name,
            'start_at' => $tz->toUtc($date, '08:30'),
            'end_at' => $tz->toUtc($date, '18:00'),
            'registration_open_at' => null,
            'registration_close_at' => null,
            'registration_fee_cents' => 13000,
            'max_teams' => 16,
            'courts' => 4,
            'min_games' => 3,
            'format' => 'Pool Play + Gold/Silver',
            'modality' => Modality::TWO_VS_TWO,
            'gender_category' => GenderCategory::MALE,
            'level_category' => LevelCategory::ADVANCED,
            'age_category' => AgeCategory::ADULT,
            'event_type' => EventType::COMPETITIVE,
            'prize_description' => null,
            'rules' => [],
            'teams_registered_count' => 0,
            'status' => EventStatus::DRAFT,
            'published_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'created_by' => null,
        ];
    }

    public function free(): static
    {
        return $this->state(fn (array $attributes): array => [
            'registration_fee_cents' => 0,
        ]);
    }

    public function withoutTeamLimit(): static
    {
        return $this->state(fn (array $attributes): array => [
            'max_teams' => null,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EventStatus::PUBLISHED,
            'published_at' => CarbonImmutable::now(),
        ]);
    }

    public function registrationOpen(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EventStatus::REGISTRATION_OPEN,
            'published_at' => CarbonImmutable::now(),
        ]);
    }

    public function registrationClosed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EventStatus::REGISTRATION_CLOSED,
            'published_at' => CarbonImmutable::now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EventStatus::CANCELLED,
            'cancelled_at' => CarbonImmutable::now(),
            'cancellation_reason' => 'Chuva forte prevista para o fim de semana.',
        ]);
    }

    public function forOrganizer(Organizer $organizer): static
    {
        return $this->state(fn (array $attributes): array => [
            'organizer_id' => $organizer->id,
        ]);
    }
}
