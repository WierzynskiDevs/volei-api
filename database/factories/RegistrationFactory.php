<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Registrations\Domain\Enums\LevelReview;
use App\Modules\Registrations\Domain\Enums\PartnerMode;
use App\Modules\Registrations\Domain\Enums\RegistrationStatus;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use App\Modules\Users\Domain\Enums\PlayerLevel;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Registration>
 */
final class RegistrationFactory extends Factory
{
    protected $model = Registration::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'group_id' => null,
            'is_captain' => false,
            'partner_mode' => PartnerMode::INDIVIDUAL,
            'status' => RegistrationStatus::PENDING_PAYMENT,
            'level_review' => LevelReview::NOT_REQUIRED,
            'player_level' => null,
            'event_level' => LevelCategory::ADVANCED,
            'level_review_decided_by' => null,
            'level_review_decided_at' => null,
            'level_review_reason' => null,
            'reserved_until' => CarbonImmutable::now()->addHour(),
            'confirmed_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'accepted_rules_version' => '1.0',
        ];
    }

    public function forEvent(Event $event): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_id' => $event->id,
            'event_level' => $event->level_category,
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user->id,
            'player_level' => $user->level,
        ]);
    }

    /**
     * Confirmada. `confirmed_at` é obrigatório junto — o CHECK
     * `registrations_confirmed_check` recusa CONFIRMED sem instante.
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RegistrationStatus::CONFIRMED,
            'confirmed_at' => CarbonImmutable::now(),
            'reserved_until' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RegistrationStatus::CANCELLED,
            'cancelled_at' => CarbonImmutable::now(),
            'reserved_until' => null,
        ]);
    }

    /** Reserva já vencida: ocupa estado, mas não ocupa vaga (ADR 0003). */
    public function reservationExpired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RegistrationStatus::PENDING_PAYMENT,
            'reserved_until' => CarbonImmutable::now()->subMinute(),
        ]);
    }

    public function awaitingLevelReview(): static
    {
        return $this->state(fn (array $attributes): array => [
            'level_review' => LevelReview::REQUIRED,
            'player_level' => PlayerLevel::OPEN,
            'event_level' => LevelCategory::INTERMEDIATE,
        ]);
    }

    public function inGroup(RegistrationGroup $group, bool $captain = false): static
    {
        return $this->state(fn (array $attributes): array => [
            'group_id' => $group->id,
            'is_captain' => $captain,
            'partner_mode' => PartnerMode::PARTNER,
        ]);
    }

    /** Convite de dupla ainda não respondido (Q14, ADR 0015) — combinar com `inGroup()`. */
    public function pendingAcceptance(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RegistrationStatus::PENDING_ACCEPTANCE,
            'reserved_until' => null,
        ]);
    }
}
