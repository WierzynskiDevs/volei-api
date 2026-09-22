<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação de forma para `PUT /organizer/events/{slug}/courts` (ADR 0013 §8).
 *
 * Autorização real é a Policy do evento, chamada no controller.
 */
final class SetCourtsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'courts' => ['required', 'array', 'min:1', 'max:32'],
            'courts.*' => ['required', 'string', 'max:60'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $labels = array_map('trim', (array) $this->input('courts', []));

            if (count($labels) !== count(array_unique($labels))) {
                $validator->errors()->add('courts', 'Os nomes das quadras não podem se repetir.');
            }
        });
    }

    /** @return list<string> */
    public function labels(): array
    {
        /** @var array<int, string> $raw */
        $raw = (array) $this->input('courts', []);

        return array_values(array_map(fn (string $label): string => trim($label), $raw));
    }
}
