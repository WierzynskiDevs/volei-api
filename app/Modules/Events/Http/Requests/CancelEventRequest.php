<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelamento de evento (BRIEF §35/§36).
 *
 * A justificativa é obrigatória e tem mínimo real de caracteres: cancelar
 * dispara obrigação de reembolso, e "cancelado" sozinho não serve como
 * evidência para o inscrito nem para a auditoria.
 */
final class CancelEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // autorização real na Policy, chamada no controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'justification' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'justification.required' => 'Explique o motivo do cancelamento — os inscritos serão informados.',
            'justification.min' => 'A justificativa precisa ter ao menos 10 caracteres.',
        ];
    }
}
