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
use Illuminate\Support\Str;

/**
 * A dupla (ADR 0001).
 *
 * Model é persistência (CLAUDE.md §4.3). Os únicos métodos além de relação e
 * cast são `displayName()`/`publicDisplayName()`, formatação de
 * apresentação — não decidem nada de negócio.
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

    /**
     * Nome da dupla para exibição PÚBLICA (ADR 0017): primeiro nome de cada
     * membro, nunca o sobrenome — reduz a superfície de identificação numa
     * lista que qualquer visitante do link do evento pode ver, diferente de
     * `displayName()` (organizador autenticado, nome completo). Mesma regra
     * de fallback para `display_name` manual e para relação não carregada.
     */
    public function publicDisplayName(): string
    {
        if ($this->display_name !== null && trim($this->display_name) !== '') {
            return $this->display_name;
        }

        if (! $this->relationLoaded('registrations')) {
            return 'Dupla';
        }

        $firstNames = $this->registrations
            ->map(function (Registration $r): ?string {
                $name = $r->relationLoaded('user') ? $r->user?->name : null;

                return $name === null || $name === '' ? null : Str::of($name)->trim()->before(' ')->toString();
            })
            ->filter(fn (?string $name): bool => $name !== null && $name !== '')
            ->all();

        return $firstNames === [] ? 'Dupla' : implode(' / ', $firstNames);
    }
}
