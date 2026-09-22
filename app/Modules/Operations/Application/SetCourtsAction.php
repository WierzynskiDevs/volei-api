<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Infrastructure\Models\Court;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Define as quadras do evento (ADR 0013 §2/§8 — S8a).
 *
 * Consumidor: `PUT /organizer/events/{slug}/courts`. Substitui o conjunto
 * inteiro — não há diff parcial porque, nesta fatia, nada mais referencia
 * `courts.id` (partidas chegam em S9); apagar e recriar é simples e correto.
 * Quando `matches.court_id` existir, este método precisa revisitar isto
 * (preservar id de quadra já usada por partida).
 */
final readonly class SetCourtsAction
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  list<string>  $labels  ordem = posição no kanban
     * @return Collection<int, Court>
     */
    public function execute(Event $event, User $actor, array $labels): Collection
    {
        $courts = DB::transaction(function () use ($event, $labels): Collection {
            Court::query()->where('event_id', $event->id)->delete();

            $created = [];

            foreach (array_values($labels) as $position => $label) {
                $created[] = Court::query()->create([
                    'event_id' => $event->id,
                    'label' => $label,
                    'position' => $position,
                ]);
            }

            return new Collection($created);
        });

        $this->audit->log(
            action: AuditAction::EVENT_COURTS_UPDATED,
            actor: $actor,
            targetType: 'event',
            targetId: $event->id,
            metadata: [
                'court_labels' => $labels,
                'court_count' => count($labels),
            ],
        );

        return $courts;
    }
}
