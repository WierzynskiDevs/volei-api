<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /organizer/events/{slug}/matches/{match}/sets` (ADR 0013 §4/§8 — S9).
 *
 * Um set por chamada — placar final, nunca ponto a ponto (ATA §17).
 */
final class RecordMatchSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'set_number' => ['required', 'integer', 'min:1'],
            'score_a' => ['required', 'integer', 'min:0'],
            'score_b' => ['required', 'integer', 'min:0'],
        ];
    }

    public function setNumber(): int
    {
        return $this->integer('set_number');
    }

    public function scoreA(): int
    {
        return $this->integer('score_a');
    }

    public function scoreB(): int
    {
        return $this->integer('score_b');
    }
}
