<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Organizers\Domain\Enums\OrganizerStatus;
use App\Modules\Organizers\Domain\Enums\PaymentAccountStatus;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Organizers\Infrastructure\Models\Plan;
use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organizer>
 */
final class OrganizerFactory extends Factory
{
    protected $model = Organizer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'user_id' => User::factory()->withRole(Role::ORGANIZER),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => null,
            'contact_email' => fake()->safeEmail(),
            'contact_phone_encrypted' => null,
            'city' => fake()->city(),
            'state' => fake()->randomElement(['SC', 'PR', 'SP', 'PE', 'RN', 'ES']),
            'plan_id' => fn (): string => self::planId('PRO'),
            'status' => OrganizerStatus::REGULAR,

            /*
             * Vinculado por padrão porque é o cenário normal de teste: o que se
             * quer exercitar quase sempre é o fluxo do organizador que já pode
             * receber. O caminho bloqueado tem estado próprio, abaixo, e é ele
             * que o teste de "publicar pago sem conta" usa.
             */
            'payment_account_status' => PaymentAccountStatus::LINKED,
            'payment_account_external_id' => 'fake_'.Str::lower(Str::random(12)),
        ];
    }

    /** Organizador sem destino para o dinheiro (docs/OPEN-QUESTIONS.md Q6). */
    public function withoutPaymentAccount(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_account_status' => PaymentAccountStatus::NOT_LINKED,
            'payment_account_external_id' => null,
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrganizerStatus::BLOCKED,
        ]);
    }

    public function onPlan(string $code): static
    {
        return $this->state(fn (array $attributes): array => [
            'plan_id' => self::planId($code),
        ]);
    }

    /**
     * Os planos são dado de referência, criados pelo `PlanSeeder`. Testes com
     * `RefreshDatabase` não rodam seeders, então a fábrica garante a linha —
     * com a MESMA taxa do seeder, senão um teste financeiro passaria contra um
     * plano fictício e falharia em produção.
     */
    private static function planId(string $code): string
    {
        $defaults = [
            'FREE' => ['fee' => 500, 'events' => 3],
            'PRO' => ['fee' => 350, 'events' => 20],
            'PREMIUM' => ['fee' => 250, 'events' => null],
        ];

        $plan = $defaults[$code] ?? $defaults['PRO'];

        return (string) Plan::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => $code,
                'status' => 'ACTIVE',
                'monthly_price_cents' => 0,
                'platform_fee_basis_points' => $plan['fee'],
                'platform_fee_fixed_cents' => 0,
                'event_limit' => $plan['events'],
                'registration_limit' => null,
                'features' => [],
                'sort_order' => 0,
            ],
        )->id;
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user->id,
        ]);
    }
}
