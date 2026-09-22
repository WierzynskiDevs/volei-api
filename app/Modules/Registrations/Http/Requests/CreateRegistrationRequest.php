<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Http\Requests;

use App\Modules\Registrations\Application\DTO\RegistrationData;
use App\Modules\Registrations\Domain\Enums\PartnerMode;
use App\Modules\Registrations\Domain\Enums\ShirtSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação de forma da criação de inscrição (CLAUDE.md §11: allowlist).
 *
 * FormRequest valida **forma e tipo**, nunca regra dependente de estado
 * (CLAUDE.md §4.3): "o evento aceita inscrição?", "ainda tem vaga?", "o parceiro
 * já está inscrito?" são invariantes que precisam de lock e vivem na action.
 *
 * Note o que o cliente NÃO envia: valor, nível, status, ocupação de vaga.
 * Nenhum desses aceita palpite do cliente (CLAUDE.md §27.7).
 */
final class CreateRegistrationRequest extends FormRequest
{
    /** A autorização é feita por Policy no controller. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'partner_mode' => ['required', Rule::enum(PartnerMode::class)],

            /*
             * Obrigatório apenas no modo PARTNER, e proibido nos outros: enviar
             * parceiro num modo "individual" é pedido incoerente, e aceitar em
             * silêncio criaria dupla que a tela não pediu.
             */
            'partner_user_id' => [
                'nullable',
                'uuid',
                Rule::requiredIf(fn (): bool => $this->input('partner_mode') === PartnerMode::PARTNER->value),
                Rule::prohibitedIf(fn (): bool => $this->input('partner_mode') !== PartnerMode::PARTNER->value),
            ],

            /*
             * A tela afirma "ao se inscrever você aceita o regulamento v1.0".
             * CLAUDE.md §12 exige consentimento gravado com versão, então o
             * aceite é explícito e obrigatório — não implícito no clique.
             */
            'accept_rules' => ['required', 'accepted'],

            /*
             * Q19/ADR 0016 — validação de FORMA apenas (CLAUDE.md §4.3): se o
             * evento exige ou não cada campo é regra de estado, e vive na
             * action. Aqui só se garante que, quando enviado, o valor tem
             * shape válido — allowlist real para shirt_size (§11).
             */
            'emergency_contact_name' => ['nullable', 'string', 'max:160'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20'],
            'shirt_size' => ['nullable', Rule::enum(ShirtSize::class)],
            'team_name' => ['nullable', 'string', 'max:60'],
            'dietary_restriction' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'partner_user_id.required' => 'Selecione um parceiro para se inscrever com dupla.',
            'partner_user_id.prohibited' => 'Este modo de inscrição não aceita parceiro.',
            'accept_rules.accepted' => 'É necessário aceitar o regulamento do campeonato.',
        ];
    }

    public function toData(): RegistrationData
    {
        return new RegistrationData(
            partnerMode: PartnerMode::from((string) $this->string('partner_mode')),
            partnerUserId: $this->input('partner_user_id') === null
                ? null
                : (string) $this->string('partner_user_id'),
            acceptedRules: $this->boolean('accept_rules'),
            emergencyContactName: $this->filled('emergency_contact_name')
                ? (string) $this->string('emergency_contact_name')
                : null,
            emergencyContactPhone: $this->filled('emergency_contact_phone')
                ? (string) $this->string('emergency_contact_phone')
                : null,
            shirtSize: $this->filled('shirt_size') ? ShirtSize::from((string) $this->string('shirt_size')) : null,
            teamName: $this->filled('team_name') ? (string) $this->string('team_name') : null,
            dietaryRestriction: $this->filled('dietary_restriction')
                ? (string) $this->string('dietary_restriction')
                : null,
        );
    }
}
