<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Application\DTO;

use App\Modules\Brackets\Infrastructure\Models\BracketSlot;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use Illuminate\Support\Collection;

/**
 * Composição de leitura para `BracketResource` (evento + posições + duplas
 * ainda elegíveis). Não é Eloquent — só agrupa o que `BracketDirectory` já
 * buscou, para o Resource não precisar de três parâmetros soltos.
 */
final readonly class BracketView
{
    /**
     * @param  Collection<int, BracketSlot>  $slots
     * @param  Collection<int, RegistrationGroup>  $eligibleGroups
     */
    public function __construct(
        public Event $event,
        public Collection $slots,
        public Collection $eligibleGroups,
    ) {}
}
