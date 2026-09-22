<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Infrastructure\Models;

use App\Shared\Domain\FeeRate;
use App\Shared\Domain\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plano da plataforma (BRIEF §26).
 *
 * A taxa vive aqui — nunca hardcoded no código (BRIEF §25).
 * Alterar a taxa deste plano NÃO altera pagamentos já criados: a taxa é
 * congelada na cobrança (BRIEF §27, CLAUDE.md §7.5).
 *
 * @property string $id
 * @property string $code
 * @property int $platform_fee_basis_points
 */
final class Plan extends Model
{
    use HasUuids;

    protected $table = 'plans';

    protected $fillable = [
        'code',
        'name',
        'description',
        'status',
        'monthly_price_cents',
        'platform_fee_basis_points',
        'platform_fee_fixed_cents',
        'event_limit',
        'registration_limit',
        'features',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'monthly_price_cents' => 'integer',
            'platform_fee_basis_points' => 'integer',
            'platform_fee_fixed_cents' => 'integer',
            'event_limit' => 'integer',
            'registration_limit' => 'integer',
        ];
    }

    /** Taxa vigente do plano, como value object. */
    public function feeRate(): FeeRate
    {
        return FeeRate::fromBasisPoints($this->platform_fee_basis_points);
    }

    public function fixedFee(): Money
    {
        return Money::fromCents($this->platform_fee_fixed_cents);
    }

    public function monthlyPrice(): Money
    {
        return Money::fromCents($this->monthly_price_cents);
    }

    public function hasUnlimitedEvents(): bool
    {
        return $this->event_limit === null;
    }

    /** @return HasMany<Organizer, $this> */
    public function organizers(): HasMany
    {
        return $this->hasMany(Organizer::class);
    }
}
