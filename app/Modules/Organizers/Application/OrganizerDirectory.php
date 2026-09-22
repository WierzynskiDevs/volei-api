<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Application;

use App\Modules\Organizers\Domain\Enums\OrganizerStatus;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Consulta de organizadores para uso administrativo (ADR 0010 §2).
 *
 * Consumidor: `/admin/organizadores`, que mostra nome, cidade, volume de
 * eventos, situação e desde quando opera.
 *
 * A contagem de eventos **não** está aqui: `events` é do módulo Events, e
 * `Organizers` não pode consultar tabela alheia (CLAUDE.md §4.1). Quem responde
 * quantos eventos cada organizador tem é `EventDirectory::countsByOrganizer()`,
 * e a composição acontece no controller de Administration.
 */
final readonly class OrganizerDirectory
{
    private const int MAX_PER_PAGE = 100;

    /** @return LengthAwarePaginator<int, Organizer> */
    public function paginate(
        ?OrganizerStatus $status = null,
        ?string $search = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $query = Organizer::query()
            ->with(['user', 'plan'])
            ->when($status !== null, fn (Builder $q): Builder => $q->where('status', $status?->value));

        if ($search !== null && trim($search) !== '') {
            $term = '%'.trim($search).'%';

            $query->where(function (Builder $q) use ($term): void {
                $q->where('name', 'ILIKE', $term)
                    ->orWhere('contact_email', 'ILIKE', $term)
                    ->orWhere('city', 'ILIKE', $term);
            });
        }

        return $query
            ->orderBy('name')
            ->paginate(min($perPage, self::MAX_PER_PAGE))
            ->withQueryString();
    }

    /**
     * Um organizador, com o necessário para a tela de detalhe.
     *
     * Traz o histórico de plano junto: `/admin/organizadores/{id}` tem a seção
     * "Histórico de planos", e é a única tela que a consome. Carregar em
     * chamada separada seria uma requisição a mais para dado que sempre é lido
     * junto.
     */
    public function find(string $id): ?Organizer
    {
        return Organizer::query()
            ->with(['user', 'plan', 'planHistory' => fn ($q) => $q->with('plan')->orderByDesc('effective_at')])
            ->find($id);
    }

    /**
     * Carrega organizadores por id, para compor relatório financeiro com nome.
     *
     * @param  list<string>  $ids
     * @return Collection<string, Organizer> indexada por id
     */
    public function findMany(array $ids): Collection
    {
        if ($ids === []) {
            /** @var Collection<string, Organizer> */
            return collect();
        }

        /** @var Collection<string, Organizer> */
        return Organizer::query()
            ->with('plan')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    public function countActive(): int
    {
        return Organizer::query()->where('status', '!=', OrganizerStatus::BLOCKED->value)->count();
    }
}
