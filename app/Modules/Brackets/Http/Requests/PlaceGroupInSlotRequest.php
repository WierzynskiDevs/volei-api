<?php

declare(strict_types=1);

namespace App\Modules\Brackets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Encaixe manual de dupla numa posição (ADR 0011).
 *
 * `registration_group_id` nulo limpa a posição — é uma requisição válida, não
 * um erro de validação.
 */
final class PlaceGroupInSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // autorização real na Policy, chamada no controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'registration_group_id' => ['nullable', 'uuid', 'exists:registration_groups,id'],
        ];
    }
}
