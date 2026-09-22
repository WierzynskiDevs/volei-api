<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /organizer/events/{slug}/matches/{match}/referee` (ADR 0013 §4/§8).
 *
 * Só confere que `referee_id` existe — pertencer ao mesmo evento da partida
 * é regra de estado, verificada em `AssignMatchRefereeAction`
 * (`RefereeNotInEventException`).
 */
final class AssignMatchRefereeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'referee_id' => ['nullable', 'uuid', 'exists:event_referees,id'],
        ];
    }

    public function refereeId(): ?string
    {
        return $this->filled('referee_id') ? (string) $this->string('referee_id') : null;
    }
}
