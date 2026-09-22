<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Domain\Enums\MatchStatus;
use App\Modules\Operations\Infrastructure\Models\Court;
use App\Modules\Operations\Infrastructure\Models\EventReferee;
use App\Modules\Operations\Infrastructure\Models\GameMatch;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameMatch>
 */
final class GameMatchFactory extends Factory
{
    protected $model = GameMatch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $event = Event::factory()->create();

        return [
            'event_id' => $event->id,
            'court_id' => null,
            'referee_id' => null,
            'team_a_id' => RegistrationGroup::factory()->forEvent($event)->complete(),
            'team_b_id' => RegistrationGroup::factory()->forEvent($event)->complete(),
            'status' => MatchStatus::PENDENTE,
        ];
    }

    public function forEvent(Event $event): static
    {
        return $this->state(fn (array $attributes): array => ['event_id' => $event->id]);
    }

    public function betweenTeams(RegistrationGroup $teamA, RegistrationGroup $teamB): static
    {
        return $this->state(fn (array $attributes): array => [
            'team_a_id' => $teamA->id,
            'team_b_id' => $teamB->id,
        ]);
    }

    public function assigned(Court $court): static
    {
        return $this->state(fn (array $attributes): array => [
            'court_id' => $court->id,
            'status' => MatchStatus::ATRIBUIDA,
        ]);
    }

    public function ready(Court $court, EventReferee $referee): static
    {
        return $this->state(fn (array $attributes): array => [
            'court_id' => $court->id,
            'referee_id' => $referee->id,
            'status' => MatchStatus::PRONTA,
        ]);
    }

    public function inProgress(Court $court, EventReferee $referee): static
    {
        return $this->state(fn (array $attributes): array => [
            'court_id' => $court->id,
            'referee_id' => $referee->id,
            'status' => MatchStatus::EM_ANDAMENTO,
            'started_at' => now(),
        ]);
    }

    public function finished(Court $court, EventReferee $referee): static
    {
        return $this->state(fn (array $attributes): array => [
            'court_id' => $court->id,
            'referee_id' => $referee->id,
            'status' => MatchStatus::FINALIZADA,
            'started_at' => now()->subMinutes(35),
            'finished_at' => now(),
        ]);
    }
}
