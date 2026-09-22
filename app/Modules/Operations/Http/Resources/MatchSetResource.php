<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Resources;

use App\Modules\Operations\Infrastructure\Models\MatchSet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatchSet */
final class MatchSetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'set_number' => $this->set_number,
            'score_a' => $this->score_a,
            'score_b' => $this->score_b,
        ];
    }
}
