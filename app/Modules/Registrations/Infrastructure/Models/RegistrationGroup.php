<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Infrastructure\Models;

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Registrations\Domain\Enums\GroupStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RegistrationGroupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dupla (ADR 0001).
 *
 * Model é persistência (CLAUDE.md §4.3). O único método além de relação e cast é
 * `displayName()`, que é formatação de apresentação — não decide nada.
 *
 * @property string $id
 * @property string $event_id
 * @property string|null $display_name
 * @property GroupStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class RegistrationGroup extends Model
{
    /** @use HasFactory<RegistrationGroupFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'registration_groups';

    /** `status` fica fora: é consequência do estado das inscrições membros. */
    protected $fillable = [
        'event_id',
        'display_name',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => GroupStatus::class,
        ];
    }

    protected static function newFactory(): RegistrationGroupFactory
    {
        return RegistrationGroupFactory::new();
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<Registration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class, 'group_id');
    }

    /**
     * Nome da dupla para exibição.
     *
     * `display_name` só é usado quando o organizador editou de fato (o lápis na
     * aba Duplas). Sem ele, deriva dos nomes dos membros — assim uma dupla nunca
     * aparece sem nome na tela, e o valor derivado acompanha troca de parceiro
     * sem precisar de update.
     *
     * Exige `registrations.user` carregado; sem isso devolve o fallback em vez
     * de disparar lazy load (`preventLazyLoading` está ativo).
     */
    public function displayName(): string
    {
        if ($this->display_name !== null && trim($this->display_name) !== '') {
            return $this->display_name;
        }

        if (! $this->relationLoaded('registrations')) {
            return 'Dupla';
        }

        $names = $this->registrations
            ->map(fn (Registration $r): ?string => $r->relationLoaded('user') ? $r->user?->name : null)
            ->filter(fn (?string $name): bool => $name !== null && $name !== '')
            ->all();

        return $names === [] ? 'Dupla' : implode(' / ', $names);
    }
}
