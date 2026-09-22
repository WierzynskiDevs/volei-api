<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Domain\Enums\GenderCategory;
use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Events\Http\Resources\EventResource;
use App\Modules\Events\Infrastructure\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Vitrine pública de eventos — consumida por `/eventos` e `/eventos/{slug}`
 * no volei-app. Não exige autenticação: a página do evento é pública, como o
 * próprio baseline anuncia ("não é necessário ter conta para visualizar").
 *
 * Controller magro (CLAUDE.md §4.3): monta a query de leitura, pagina e
 * devolve Resource. Nenhuma regra de negócio.
 */
final class PublicEventController
{
    /** Teto de itens por página (CLAUDE.md §6: toda lista é paginada). */
    private const int MAX_PER_PAGE = 48;

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min($request->integer('per_page', 24), self::MAX_PER_PAGE);

        $events = Event::query()
            ->publiclyListed()
            /*
             * Eager loading explícito: sem isto a listagem faz uma query de
             * organizador por evento (CLAUDE.md §26, proibição de N+1).
             */
            ->with('organizer')
            ->when(
                $request->filled('gender_category'),
                fn (Builder $q) => $q->where(
                    'gender_category',
                    GenderCategory::from((string) $request->string('gender_category'))->value,
                ),
            )
            ->when(
                $request->filled('level_category'),
                fn (Builder $q) => $q->where(
                    'level_category',
                    LevelCategory::from((string) $request->string('level_category'))->value,
                ),
            )
            ->when(
                $request->filled('state'),
                fn (Builder $q) => $q->where('state', mb_strtoupper((string) $request->string('state'))),
            )
            /*
             * Filtro de status combinado com `publiclyListed()`: o parâmetro
             * restringe dentro do que já é público, nunca amplia. Pedir
             * `status=DRAFT` devolve lista vazia — não os rascunhos alheios.
             *
             * Consumidor: a home, que mostra "inscrições abertas".
             */
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where(
                    'status',
                    EventStatus::from((string) $request->string('status'))->value,
                ),
            )
            ->when(
                $request->filled('q'),
                fn (Builder $q) => $this->applySearch($q, (string) $request->string('q')),
            )
            // Vitrine é cronológica: o que acontece antes aparece antes.
            ->orderBy('start_at')
            ->paginate($perPage)
            ->withQueryString();

        return EventResource::collection($events);
    }

    public function show(string $slug): JsonResponse
    {
        $event = Event::query()
            ->publiclyVisible()
            ->with('organizer')
            ->where('slug', $slug)
            ->firstOrFail();

        return EventResource::make($event)->response();
    }

    /**
     * Busca por nome, cidade, arena e formato — os mesmos campos que o
     * baseline varre no filtro em memória.
     *
     * `ILIKE` com bind parametrizado; nunca concatenação (CLAUDE.md §11).
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function applySearch(Builder $query, string $term): Builder
    {
        // Escapa os curingas do LIKE para que "100%" seja buscado como texto.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($term));
        $like = '%'.$escaped.'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('name', 'ILIKE', $like)
                ->orWhere('city', 'ILIKE', $like)
                ->orWhere('venue_name', 'ILIKE', $like)
                ->orWhere('format', 'ILIKE', $like);
        });
    }
}
