<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Cenário base: R$ 130,00 com taxa de 5% + R$ 0 fixo = R$ 6,50.
     * É o exemplo do BRIEF §29, para que os testes falem do mesmo caso.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $reference = (string) Str::uuid7();

        return [
            'registration_id' => Registration::factory(),
            'event_id' => Event::factory(),
            'organizer_id' => Organizer::factory(),
            'user_id' => User::factory(),
            'method' => PaymentMethod::PIX,
            'status' => PaymentStatus::PENDING,
            'gross_cents' => 13000,
            'platform_fee_basis_points' => 500,
            'platform_fee_fixed_cents' => 0,
            'platform_fee_cents' => 650,
            // Nascem nulos: só o gateway sabe a taxa (ADR 0009 §5).
            'asaas_fee_cents' => null,
            'organizer_net_cents' => null,
            'refunded_cents' => 0,
            'provider' => 'fake',
            'provider_payment_id' => 'fake_'.Str::lower(Str::random(16)),
            'external_reference' => $reference,
            'checkout_url' => null,
            'pix_payload' => null,
            'idempotency_key' => (string) Str::uuid7(),
            'due_at' => CarbonImmutable::now()->addHour(),
            'confirmed_at' => null,
            'received_at' => null,
            'refunded_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
            'reconciled_at' => null,
        ];
    }

    public function forRegistration(Registration $registration): static
    {
        return $this->state(fn (array $attributes): array => [
            'registration_id' => $registration->id,
            'event_id' => $registration->event_id,
            'user_id' => $registration->user_id,
        ]);
    }

    public function forOrganizer(Organizer $organizer): static
    {
        return $this->state(fn (array $attributes): array => [
            'organizer_id' => $organizer->id,
        ]);
    }

    /**
     * Pago, saldo ainda não disponível. Taxa do gateway já informada, então o
     * líquido existe — o CHECK `payments_net_identity_check` exige coerência.
     */
    public function confirmed(int $asaasFeeCents = 199): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::CONFIRMED,
            'confirmed_at' => CarbonImmutable::now(),
            'asaas_fee_cents' => $asaasFeeCents,
            'organizer_net_cents' => $attributes['gross_cents']
                - $attributes['platform_fee_cents']
                - $asaasFeeCents,
        ]);
    }

    /** Liquidado: o dinheiro está disponível na conta do organizador. */
    public function received(int $asaasFeeCents = 199): static
    {
        return $this->confirmed($asaasFeeCents)->state(fn (array $attributes): array => [
            'status' => PaymentStatus::RECEIVED,
            'received_at' => CarbonImmutable::now(),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::OVERDUE,
            'due_at' => CarbonImmutable::now()->subHour(),
        ]);
    }

    public function creditCard(): static
    {
        return $this->state(fn (array $attributes): array => [
            'method' => PaymentMethod::CREDIT_CARD,
        ]);
    }
}
