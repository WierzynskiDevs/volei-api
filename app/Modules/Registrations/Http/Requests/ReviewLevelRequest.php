<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Decisão de análise de nível.
 *
 * O aditivo §17 manda registrar "decisão, usuário, data, motivo quando
 * aplicável". Quem decide e quando é o servidor que resolve — o cliente só
 * manda o motivo, e ele é opcional porque aprovar raramente precisa de
 * justificativa.
 */
final class ReviewLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function reason(): ?string
    {
        $reason = trim((string) $this->string('reason'));

        return $reason === '' ? null : $reason;
    }
}
