<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /organizer/events/{slug}/referees/{referee}` (ADR 0013 §8 — S8b).
 *
 * Só confere que `court_id` EXISTE — se pertence ao mesmo evento do juiz é
 * regra de estado, verificada em `SetRefereeCourtAction`
 * (`CourtNotInEventException`), mesmo padrão de `PlaceGroupInSlotAction`
 * para dupla fora do evento (CLAUDE.md §4.3).
 */
final class SetRefereeCourtRequest extends FormRequest
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
