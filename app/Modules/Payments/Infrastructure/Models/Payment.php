<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Models;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\PaymentBreakdown;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Users\Infrastructure\Models\User;
use App\Shared\Domain\FeeRate;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cobrança de um atleta (BRIEF §31, ADR 0001: 1:1 com a inscrição).
 *
 * Sem soft delete e sem delete: o trigger `payments_no_delete` recusa no banco.
 * Registro financeiro é append-only quanto à existência (CLAUDE.md §6).
 *
 * @property string $id
 * @property string $registration_id
 * @property string $event_id
 * @property string $organizer_id
 * @property string $user_id
 * @property PaymentMethod $method
 * @property PaymentStatus $status
 * @property int $gross_cents
 * @property int $platform_fee_basis_points
 * @property int $platform_fee_fixed_cents
 * @property int $platform_fee_cents
 * @property int|null $asaas_fee_cents
 * @property int|null $organizer_net_cents
 * @property int $refunded_cents
 * @property string $provider
 * @property string|null $provider_payment_id
 * @property string $external_reference
 * @property string|null $checkout_url
 * @property string|null $pix_payload
 * @property string $idempotency_key
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $received_at
 * @property CarbonImmutable|null $refunded_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $reconciled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payments';

    /**
     * Allowlist explícita (CLAUDE.md §5).
     *
     * Todo o bloco de dinheiro e de estado fica **fora**: valor, taxas, líquido,
     * status e instantes são consequência de ação e de evento do gateway, nunca
     * de formulário. Nenhum request consegue escrever quanto o organizador
     * recebe.
     */
    protected $fillable = [
        'registration_id',
        'event_id',
        'organizer_id',
        'user_id',
        'method',
        'provider',
        'external_reference',
        'idempotency_key',
        'due_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'gross_cents' => 'integer',
            'platform_fee_basis_points' => 'integer',
            'platform_fee_fixed_cents' => 'integer',
            'platform_fee_cents' => 'integer',
            'asaas_fee_cents' => 'integer',
            'organizer_net_cents' => 'integer',
            'refunded_cents' => 'integer',
            'due_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    /** @return BelongsTo<Registration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Organizer, $this> */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PaymentWebhookEvent, $this> */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class);
    }

    /*
     * ---------------------------------------------------------------- *
     * Leitura de dinheiro — value objects, nunca int solto
     * ---------------------------------------------------------------- */

    public function gross(): Money
    {
        return Money::fromCents($this->gross_cents);
    }

    public function platformFee(): Money
    {
        return Money::fromCents($this->platform_fee_cents);
    }

    public function asaasFee(): ?Money
    {
        return $this->asaas_fee_cents === null ? null : Money::fromCents($this->asaas_fee_cents);
    }

    /** `null` = taxa do gateway ainda desconhecida (ADR 0009 §5). */
    public function organizerNet(): ?Money
    {
        return $this->organizer_net_cents === null
            ? null
            : Money::fromCents($this->organizer_net_cents);
    }

    public function platformFeeRate(): FeeRate
    {
        return FeeRate::fromBasisPoints($this->platform_fee_basis_points);
    }

    /**
     * Reconstrói a decomposição a partir do que está persistido.
     *
     * `fromPersisted`, nunca `forCharge`: recalcular a taxa de um pagamento
     * existente é proibido (CLAUDE.md §7.5).
     */
    public function breakdown(): PaymentBreakdown
    {
        return PaymentBreakdown::fromPersisted(
            gross: $this->gross(),
            platformFee: $this->platformFee(),
            platformFeeRate: $this->platformFeeRate(),
            platformFeeFixed: Money::fromCents($this->platform_fee_fixed_cents),
            asaasFee: $this->asaasFee(),
        );
    }

    /** @param  Builder<Payment>  $query */
    public function scopeSettled(Builder $query): void
    {
        $query->where('status', PaymentStatus::RECEIVED->value);
    }

    /**
     * Cobranças que ainda podem ser pagas — as que a reconciliação precisa
     * olhar (CLAUDE.md §14).
     *
     * @param  Builder<Payment>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', PaymentStatus::openValues());
    }
}
