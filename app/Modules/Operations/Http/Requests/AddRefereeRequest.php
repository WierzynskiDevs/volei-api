<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Requests;

use App\Modules\Operations\Domain\Services\RefereePhoneProtector;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

/**
 * Validação de forma para `POST /organizer/events/{slug}/referees`
 * (ADR 0013 §5/§8).
 *
 * O formato do telefone (DDD + número válido) É validação de forma — por
 * isso é conferido aqui, com `RefereePhoneProtector::normalize()`, e não
 * silenciosamente deixado estourar como erro 500 na action.
 */
final class AddRefereeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'phone' => ['required', 'string', 'max:20'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $phone = $this->input('phone');

            if (! is_string($phone) || $phone === '') {
                return; // 'required' já cobre isto.
            }

            try {
                app(RefereePhoneProtector::class)->normalize($phone);
            } catch (InvalidArgumentException) {
                $validator->errors()->add('phone', 'Informe um telefone brasileiro válido, com DDD.');
            }
        });
    }

    public function nameInput(): string
    {
        return trim((string) $this->string('name'));
    }

    public function phoneInput(): string
    {
        return (string) $this->string('phone');
    }
}
