<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Application\DTO;

use App\Modules\Brackets\Infrastructure\Models\BracketSlot;
use App\Modules\Events\Infrastructure\Models\Event;
use Illuminate\Support\Collection;

/**
 * Composição de leitura para `PublicBracketResource` (ADR 0017) — evento e
 * posições, sem `eligibleGroups`: aquilo é ferramenta de trabalho do
 * organizador durante o sorteio, nunca dado público.
 */
final readonly class PublicBracketView
{
    /** @param  Collection<int, BracketSlot>  $slots */
    public function __construct(
        public Event $event,
        public Collection $slots,
    ) {}
}
