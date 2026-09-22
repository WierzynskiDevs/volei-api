<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consulta global de eventos para uso administrativo (ADR 0010 §2).
 *
 * Diferença essencial para os scopes públicos (`publiclyListed`,
 * `publiclyVisible`) e para a lista do organizador: aqui **não há recorte**.
 * O super admin vê rascunho, cancelado e evento de qualquer organizador — é a
 * definição do acesso global do §10, e é auditado pela ação que ele dispara.
 *
 * Por isso este método está separado: um filtro esquecido numa query
 * compartilhada com a vitrine pública vazaria rascunho para o mundo.
 */
final readonly class EventDirectory
{
    private const int MAX_PER_PAGE = 100;

    /**
     * @param  list<EventStatus>|null  $statuses  vazio ou nulo = sem filtro de estado
     * @return LengthAwarePaginator<int, Event>
     */
    public function paginate(
        ?array $statuses = null,
        ?string $organizerId = null,
        ?string $search = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $query = Event::query()
            ->with('organizer')
            ->when(
                $statuses !== null && $statuses !== [],
                fn (Builder $q): Builder => $q->whereIn(
                    'status',
                    array_map(fn (EventStatus $s): string => $s->value, $statuses ?? []),
                ),
            )
            ->when($organizerId !== null, fn (Builder $q): Builder => $q->where('organizer_id', $organizerId));

        if ($search !== null && trim($search) !== '') {
            $term = '%'.trim($search).'%';

            $query->where(function (Builder $q) use ($term): void {
                $q->where('name', 'ILIKE', $term)
                    ->orWhere('city', 'ILIKE', $term)
                    ->orWhere('venue_name', 'ILIKE', $term);
            });
        }

        return $query
            ->orderByDesc('start_at')
            ->paginate(min($perPage, self::MAX_PER_PAGE))
            ->withQueryString();
    }

    /**
     * Quantos eventos cada organizador tem.
     *
     * Existe porque `Organizers` não pode contar linhas de `events`
     * (CLAUDE.md §4.1) — é o módulo dono que responde. Uma query agregada para
     * a página inteira, em vez de uma por linha: a alternativa é N+1 numa tela
     * de listagem (§26).
     *
     * @param  list<string>  $organizerIds
     * @return array<string, int> organizer_id => total
     */
    public function countsByOrganizer(array $organizerIds): array
    {
        if ($organizerIds === []) {
            return [];
        }

        /** @var array<string, int> */
        return Event::query()
            ->select('organizer_id', DB::raw('COUNT(*) AS total'))
            ->whereIn('organizer_id', $organizerIds)
            ->groupBy('organizer_id')
            ->pluck('total', 'organizer_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * Carrega eventos por id, para compor relatório financeiro com nome.
     *
     * @param  list<string>  $ids
     * @return Collection<string, Event> indexada por id
     */
    public function findMany(array $ids): Collection
    {
        if ($ids === []) {
            /** @var Collection<string, Event> */
            return collect();
        }

        /** @var Collection<string, Event> */
        return Event::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * Contagem por status, para os cartões do dashboard global.
     *
     * @return array<string, int> valor do status => total
     */
    public function countsByStatus(): array
    {
        /** @var array<string, int> */
        return Event::query()
            ->select('status', DB::raw('COUNT(*) AS total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }
}
