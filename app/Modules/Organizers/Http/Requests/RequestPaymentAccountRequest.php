<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação de forma para `POST /organizer/payment-account` (ADR 0018).
 *
 * Só o que o Asaas exige para abrir a subconta — nenhum campo de status ou
 * identificador de gateway aparece aqui (allowlist, CLAUDE.md §11).
 */
final class RequestPaymentAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mobile_phone' => ['required', 'string', 'regex:/^\d{10,11}$/'],
            'income_cents' => ['required', 'integer', 'min:0'],
            'address' => ['required', 'string', 'max:255'],
            'address_number' => ['required', 'string', 'max:20'],
            'province' => ['required', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'regex:/^\d{8}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mobile_phone.regex' => 'Informe o celular com DDD, só números.',
            'postal_code.regex' => 'Informe o CEP com 8 dígitos, sem traço.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['mobile_phone', 'postal_code'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $this->merge([$field => preg_replace('/\D/', '', $value)]);
            }
        }
    }

    public function mobilePhone(): string
    {
        return (string) $this->string('mobile_phone');
    }

    public function incomeCents(): int
    {
        return $this->integer('income_cents');
    }

    public function addressLine(): string
    {
        return trim((string) $this->string('address'));
    }

    public function addressNumber(): string
    {
        return trim((string) $this->string('address_number'));
    }

    public function province(): string
    {
        return trim((string) $this->string('province'));
    }

    public function postalCode(): string
    {
        return (string) $this->string('postal_code');
    }
}
