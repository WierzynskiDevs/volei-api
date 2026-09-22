<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Infrastructure\Models\Event;
use Illuminate\Support\Str;

/**
 * Endereço público do evento (`/eventos/{slug}`).
 *
 * O slug é decidido no servidor, nunca enviado pelo cliente: ele é o endereço
 * público e um cliente que escolhe endereço consegue colidir de propósito com
 * o evento de outro organizador.
 *
 * O baseline gera o slug a partir do nome (`slugify` em
 * `organizador.novo-evento.tsx`); aqui a regra é a mesma, com desempate por
 * sufixo numérico quando o nome já está em uso — dois "Copa Areia Curitiba" em
 * temporadas diferentes são legítimos.
 */
final readonly class EventSlugGenerator
{
    /** Sufixo máximo antes de desistir e usar um identificador aleatório. */
    private const int MAX_ATTEMPTS = 50;

    public function uniqueFor(string $name, ?string $ignoreEventId = null): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'evento';
        }

        $base = Str::limit($base, 160, '');

        if (! $this->isTaken($base, $ignoreEventId)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= self::MAX_ATTEMPTS; $suffix++) {
            $candidate = "{$base}-{$suffix}";

            if (! $this->isTaken($candidate, $ignoreEventId)) {
                return $candidate;
            }
        }

        /*
         * 50 eventos com o mesmo nome é cenário improvável, mas o método não
         * pode devolver algo que a unique constraint vá rejeitar — o sufixo
         * aleatório fecha a porta sem laço infinito.
         */
        return $base.'-'.Str::lower(Str::random(6));
    }

    /**
     * Consulta apenas entre os vivos: o índice único também é parcial
     * (`WHERE deleted_at IS NULL`), então um evento apagado libera o endereço.
     */
    private function isTaken(string $slug, ?string $ignoreEventId): bool
    {
        return Event::query()
            ->where('slug', $slug)
            ->when($ignoreEventId !== null, fn ($q) => $q->where('id', '!=', $ignoreEventId))
            ->exists();
    }
}
