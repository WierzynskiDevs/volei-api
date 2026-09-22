<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Requests;

use App\Modules\Organizers\Domain\Enums\OrganizerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mudança de situação do organizador: "Advertir" e "Suspender" em
 * `/admin/organizadores`, mais a liberação de volta para regular.
 *
 * `status` é validado contra o enum (allowlist, §11) — não existe caminho para
 * gravar um valor que a máquina de estados não conhece.
 */
final class ChangeOrganizerStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(OrganizerStatus::class)],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.required' => 'Informe a nova situação do organizador.',
            'reason.required' => 'Registre o motivo — ele fica na trilha de auditoria.',
            'reason.min' => 'O motivo precisa ter ao menos 10 caracteres.',
        ];
    }

    public function status(): OrganizerStatus
    {
        return OrganizerStatus::from((string) $this->string('status'));
    }

    public function reason(): string
    {
        return trim((string) $this->string('reason'));
    }
}
