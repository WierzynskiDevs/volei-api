<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Domain\Enums;

/** Tamanho de camiseta — catálogo fechado, mesmo raciocínio de `RegistrationFieldKey`. */
enum ShirtSize: string
{
    case PP = 'PP';
    case P = 'P';
    case M = 'M';
    case G = 'G';
    case GG = 'GG';
    case XG = 'XG';
}
