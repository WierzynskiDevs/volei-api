<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Infrastructure\Models;

use App\Modules\Users\Infrastructure\Models\User;
use App\Shared\Domain\FeeRate;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Histórico de plano do organizador.
 *
 * Append-only: é a evidência de qual taxa valia em cada período. O baseline
 * exibe isso em /admin/organizadores/$id, com a observação
 * "Migração para PRO — cobranças anteriores mantêm 5%".
 *
 * @property string $id
 * @property int $platform_fee_basis_points
 * @property string|null $note
 * @property Carbon|null $effective_at
 * @property Plan|null $plan
 */
final class OrganizerPlanHistory extends Model
{
    use HasUuids;

    protected $table = 'organizer_plan_history';

    /** Registro histórico não é atualizado. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'organizer_id',
        'plan_id',
        'platform_fee_basis_points',
        'note',
        'changed_by',
        'effective_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'platform_fee_basis_points' => 'integer',
            'effective_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** Taxa vigente no momento desta entrada — congelada. */
    public function feeRate(): FeeRate
    {
        return FeeRate::fromBasisPoints($this->platform_fee_basis_points);
    }

    /** @return BelongsTo<Organizer, $this> */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
