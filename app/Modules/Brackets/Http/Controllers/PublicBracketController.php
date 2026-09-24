<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Http\Controllers;

use App\Modules\Brackets\Application\BracketDirectory;
use App\Modules\Brackets\Application\DTO\PublicBracketView;
use App\Modules\Brackets\Http\Resources\PublicBracketResource;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Infrastructure\Models\Event;
use Illuminate\Http\JsonResponse;

/**
 * Chave pública do evento (ADR 0017, Q15). Consumidor: aba "Chaveamento" de
 * `eventos.$slug.tsx` no volei-app.
 *
 * Sem autenticação e sem `Gate::authorize` — é público de propósito, ao
 * contrário de `OrganizerBracketController::show`. Nunca reaproveita
 * `BracketResource`/`BracketDirectory::eligibleGroupsFor`: aquele Resource é
 * para o organizador (nome completo dos membros, duplas ainda não
 * encaixadas); aqui só a allowlist restrita da ADR 0017.
 */
final class PublicBracketController
{
    /**
     * Estados em que a chave já foi publicada e pode ser mostrada (ADR 0011
     * §7): em rascunho (`AWAITING_DRAW`) é ferramenta de trabalho do
     * organizador, não dado público.
     *
     * @var list<EventStatus>
     */
    private const array VISIBLE_STATUSES = [
        EventStatus::BRACKET_PUBLISHED,
        EventStatus::IN_PROGRESS,
        EventStatus::FINISHED,
    ];

    public function __construct(private readonly BracketDirectory $brackets) {}

    public function show(string $slug): JsonResponse
    {
        $event = Event::query()
            ->publiclyVisible()
            ->whereIn('status', array_map(fn (EventStatus $status): string => $status->value, self::VISIBLE_STATUSES))
            ->where('slug', $slug)
            ->firstOrFail();

        $slots = $this->brackets->slotsFor($event);

        return PublicBracketResource::make(new PublicBracketView($event, $slots))->response();
    }
}
