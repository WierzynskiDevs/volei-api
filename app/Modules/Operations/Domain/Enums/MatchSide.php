<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain\Enums;

/** Qual dupla — A ou B — num confronto (ADR 0013 §3/§4). */
enum MatchSide: string
{
    case A = 'A';
    case B = 'B';
}
