<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Infrastructure\Models;

use App\Modules\Organizers\Domain\Enums\DocumentType;
use App\Modules\Organizers\Domain\Enums\OrganizerStatus;
use App\Modules\Organizers\Domain\Enums\PaymentAccountStatus;
use App\Modules\Users\Infrastructure\Models\User;
use App\Shared\Domain\FeeRate;
use Database\Factories\OrganizerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * Organizador (BRIEF §37).
 *
 * Identidade única por UUID. NUNCA usar o nome como identificador
 * (docs/DIVERGENCES.md §7 — o baseline usa três formatos diferentes).
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property OrganizerStatus $status
 * @property PaymentAccountStatus $payment_account_status
 * @property ?DocumentType $document_type
 */
final class Organizer extends Model
{
    /** @use HasFactory<OrganizerFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'organizers';

    /**
     * `status` e `payment_account_status` ficam fora: mudança de situação é
     * ação administrativa auditada, não update de perfil.
     */
    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'contact_email',
        'contact_phone_encrypted',
        'city',
        'state',
        'plan_id',
        'document_number_encrypted',
        'document_number_hash',
        'document_type',
    ];

    /** @var list<string> */
    protected $hidden = [
        'contact_phone_encrypted',
        'payment_account_external_id',
        'document_number_encrypted',
        'document_number_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrganizerStatus::class,
            'payment_account_status' => PaymentAccountStatus::class,
            'contact_phone_encrypted' => 'encrypted',
            'document_number_encrypted' => 'encrypted',
            'document_type' => DocumentType::class,
        ];
    }

    /** Namespace modular quebra a resolução automática de fábrica. */
    protected static function newFactory(): OrganizerFactory
    {
        return OrganizerFactory::new();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<OrganizerPlanHistory, $this> */
    public function planHistory(): HasMany
    {
        return $this->hasMany(OrganizerPlanHistory::class);
    }

    /**
     * Taxa que será congelada na próxima cobrança deste organizador.
     *
     * Lê do plano vigente. O valor congelado fica no pagamento, não aqui —
     * este método nunca deve ser usado para recalcular cobrança passada.
     */
    public function currentFeeRate(): FeeRate
    {
        $plan = $this->plan;

        if (! $plan instanceof Plan) {
            // plan_id é NOT NULL com FK: chegar aqui significa relação não
            // carregada ou dado corrompido. Falhar alto é melhor do que
            // assumir taxa zero e cobrar errado.
            throw new RuntimeException("Organizador {$this->id} está sem plano associado.");
        }

        return $plan->feeRate();
    }

    /**
     * Regra de proteção do dinheiro (docs/OPEN-QUESTIONS.md Q6):
     * não se publica evento pago sem destino para o repasse.
     */
    public function canReceivePayments(): bool
    {
        return $this->status !== OrganizerStatus::BLOCKED
            && $this->payment_account_status === PaymentAccountStatus::LINKED;
    }
}
