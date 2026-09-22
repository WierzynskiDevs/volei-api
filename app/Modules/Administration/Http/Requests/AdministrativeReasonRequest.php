<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Justificativa de ação administrativa (ADR 0010 §5).
 *
 * `AuditAction::requiresJustification()` já exigia motivo para `USER_BLOCKED` e
 * `ORGANIZER_BLOCKED` desde antes deste módulo existir — a regra estava escrita
 * e sem quem a aplicasse. É este FormRequest que a aplica.
 *
 * Mínimo de 10 caracteres, como no cancelamento de evento: "spam" não sustenta
 * uma suspensão perante quem foi suspenso.
 */
final class AdministrativeReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // autorização em EnsureSuperAdmin + policy (ADR 0010 §4)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Registre o motivo — ele fica na trilha de auditoria.',
            'reason.min' => 'O motivo precisa ter ao menos 10 caracteres.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->string('reason'));
    }
}
