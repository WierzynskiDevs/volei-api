<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure\Models;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Finance\Domain\Enums\LedgerEntryType;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lançamento do ledger (CLAUDE.md §9).
 *
 * **Append-only garantido pelo banco**: triggers recusam UPDATE, DELETE e
 * TRUNCATE. Erro não se corrige com update — corrige-se com lançamento de
 * estorno.
 *
 * Por isso `$timestamps = false` e só existe `created_at`: uma linha que nunca
 * muda não tem `updated_at` que faça sentido.
 *
 * @property string $id
 * @property LedgerEntryType $type
 * @property int $amount_cents
 * @property string|null $payment_id
 * @property string $organizer_id
 * @property string|null $event_id
 * @property int|null $platform_fee_basis_points
 * @property array<string, mixed> $metadata
 * @property CarbonImmutable $created_at
 */
final class LedgerEntry extends Model
{
    use HasUuids;

    protected $table = 'ledger_entries';

    /** Append-only: não existe `updated_at`. */
    public $timestamps = false;

    protected $fillable = [
        'type',
        'amount_cents',
        'payment_id',
        'organizer_id',
        'event_id',
        'platform_fee_basis_points',
        'metadata',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_cents' => 'integer',
            'platform_fee_basis_points' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** Valor com sinal — negativo é saída (ver `LedgerEntryType`). */
    public function amount(): Money
    {
        return Money::fromCents($this->amount_cents);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Organizer, $this> */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
