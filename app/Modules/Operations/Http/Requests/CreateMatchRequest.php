<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /organizer/events/{slug}/matches` (ADR 0013 §4/§8 — S9).
 *
 * Só confere forma (uuid, presença). Elegibilidade da dupla (pertence ao
 * evento, está `COMPLETE`, não é a mesma dos dois lados) é regra de estado —
 * `CreateMatchAction` (CLAUDE.md §4.3).
 */
final class CreateMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'team_a_id' => ['required', 'uuid'],
            'team_b_id' => ['required', 'uuid'],
            'phase' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function teamAId(): string
    {
        return (string) $this->string('team_a_id');
    }

    public function teamBId(): string
    {
        return (string) $this->string('team_b_id');
    }

    public function phase(): ?string
    {
        return $this->filled('phase') ? trim((string) $this->string('phase')) : null;
    }
}
