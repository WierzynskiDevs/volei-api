<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Http\Requests;

use App\Modules\Organizers\Domain\Services\DocumentProtector;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação de criação do perfil de organizador (ADR 0014).
 *
 * Allowlist estrita (CLAUDE.md §11): `status`, `payment_account_status`,
 * `plan_id` não aparecem aqui — são consequência de ação, nunca entrada do
 * cliente.
 */
final class CreateOrganizerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Qualquer usuário autenticado pode tentar — a action recusa quem já
        // tem organizador (autorização de estado, não de papel).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],

            // Formato solto aqui de propósito — aceita com ou sem máscara.
            // Dígito verificador é conferido em withValidator(), que é onde
            // o DocumentProtector (serviço de domínio) entra em cena.
            'document_number' => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'state.regex' => 'A UF deve ter duas letras (ex.: PR).',
            'document_number.required' => 'Informe o CPF ou CNPJ.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $state = $this->input('state');

        if (is_string($state)) {
            $this->merge(['state' => mb_strtoupper(trim($state))]);
        }
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $document = $this->input('document_number');

            if (! is_string($document) || $document === '') {
                return; // 'required' já cobre isto.
            }

            if (! app(DocumentProtector::class)->isValid($document)) {
                $validator->errors()->add('document_number', 'Informe um CPF ou CNPJ válido.');
            }
        });
    }

    public function documentNumber(): string
    {
        return (string) $this->string('document_number');
    }
}
