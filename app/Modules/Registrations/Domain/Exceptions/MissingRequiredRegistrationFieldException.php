<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Domain\Exceptions;

use App\Modules\Events\Domain\Enums\RegistrationFieldKey;
use App\Shared\Domain\Exceptions\DomainException;

/**
 * O evento exige um campo (Q19, ADR 0016) que a inscrição não trouxe.
 *
 * 422, não 409: é o mesmo formato de erro de validação de campo que
 * `CreateRegistrationRequest` já produz — para a tela, é indistinguível de um
 * campo obrigatório vazio, mesmo a regra vindo do evento e não do FormRequest.
 */
final class MissingRequiredRegistrationFieldException extends DomainException
{
    public function errorCode(): string
    {
        return 'REGISTRATION_FIELD_REQUIRED';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    /** @param  list<RegistrationFieldKey>  $missing */
    public static function forFields(array $missing): self
    {
        $labels = array_map(fn (RegistrationFieldKey $f): string => $f->label(), $missing);

        return (new self(
            'Este evento exige: '.implode(', ', $labels).'.'
        ))->withContext([
            'missing_fields' => array_map(fn (RegistrationFieldKey $f): string => $f->value, $missing),
        ]);
    }
}
