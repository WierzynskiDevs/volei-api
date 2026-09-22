<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Application\DTO\RegisterUserData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validação do cadastro (BRIEF §15).
 *
 * Allowlist estrita: nada além destes campos é lido. Em especial, `role` e
 * `status` NÃO são aceitos — enviar qualquer um deles simplesmente não tem
 * efeito (CLAUDE.md §10).
 */
final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // rota pública
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:180'],

            /*
             * A tela do baseline exige apenas 6 caracteres. Aqui o mínimo é 8 e
             * há checagem contra vazamentos conhecidos: senha de 6 caracteres
             * numa plataforma que movimenta dinheiro é risco desnecessário.
             * Divergência registrada em docs/DIVERGENCES.md.
             */
            'password' => ['required', 'string', 'confirmed', $this->passwordRule()],

            'phone' => ['nullable', 'string', 'max:24'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'level' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'size:2'],

            // Aceites separados, como na tela de cadastro do baseline.
            'accept_terms' => ['required', 'accepted'],
            'accept_privacy' => ['required', 'accepted'],
        ];
    }

    /**
     * Política de senha.
     *
     * `uncompromised()` consulta a base de vazamentos do HaveIBeenPwned por
     * rede. Vale a pena em produção (senha vazada é o vetor mais barato de
     * invasão), mas os testes não podem depender de rede (CLAUDE.md §22), e uma
     * indisponibilidade externa não deve travar cadastro.
     */
    private function passwordRule(): Password
    {
        $rule = Password::min(8)->letters()->numbers();

        return app()->runningUnitTests() ? $rule : $rule->uncompromised();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accept_terms.accepted' => 'É necessário aceitar os Termos de Uso.',
            'accept_privacy.accepted' => 'É necessário aceitar a Política de Privacidade.',
            'password.confirmed' => 'As senhas não conferem.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }

        $state = $this->input('state');

        if (is_string($state)) {
            $this->merge(['state' => mb_strtoupper(trim($state))]);
        }
    }

    public function toData(): RegisterUserData
    {
        return new RegisterUserData(
            name: trim((string) $this->string('name')),
            email: (string) $this->string('email'),
            password: (string) $this->string('password'),
            phone: $this->filled('phone') ? (string) $this->string('phone') : null,
            acceptedAt: CarbonImmutable::now(),
            termsVersion: (string) config('saque.terms_version'),
            privacyVersion: (string) config('saque.privacy_version'),
            birthDate: $this->filled('birth_date') ? (string) $this->string('birth_date') : null,
            level: $this->filled('level') ? (string) $this->string('level') : null,
            city: $this->filled('city') ? (string) $this->string('city') : null,
            state: $this->filled('state') ? (string) $this->string('state') : null,
        );
    }
}
