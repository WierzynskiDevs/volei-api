<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /organizer/events/{slug}/matches/{match}/court` (ADR 0013 §4/§8).
 *
 * Só confere que `court_id` existe — pertencer ao mesmo evento da partida é
 * regra de estado, verificada em `AssignMatchCourtAction`
 * (`CourtNotInEventException`), mesmo padrão de `SetRefereeCourtRequest`.
 */
final class AssignMatchCourtRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'court_id' => ['nullable', 'uuid', 'exists:courts,id'],
        ];
    }

    public function courtId(): ?string
    {
        return $this->filled('court_id') ? (string) $this->string('court_id') : null;
    }
}
