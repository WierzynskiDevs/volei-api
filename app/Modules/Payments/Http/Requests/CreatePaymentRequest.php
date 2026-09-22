<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Domain\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Criação de cobrança.
 *
 * Note o que o cliente **não** manda: valor, taxa, líquido. Tudo isso vem do
 * evento e do plano do organizador, lidos no servidor (`CLAUDE.md` §27.6 e
 * §27.7). Aceitar valor do cliente seria deixar o navegador definir quanto se
 * paga.
 */
final class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::enum(PaymentMethod::class)],
        ];
    }

    /**
     * `paymentMethod()` e não `method()`: `Request::method()` já existe e
     * devolve o verbo HTTP. Sobrescrever quebraria o contrato da classe pai —
     * e qualquer código do framework que leia o verbo receberia um enum.
     */
    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from((string) $this->string('method'));
    }
}
