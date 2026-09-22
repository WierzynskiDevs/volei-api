<?php

declare(strict_types=1);

use App\Modules\Organizers\Domain\Enums\DocumentType;
use App\Modules\Organizers\Domain\Services\DocumentProtector;

/*
 * CPF/CNPJ do organizador (ADR 0014, CLAUDE.md §22: unit, sem banco).
 *
 * Fixtures válidas calculadas pelo próprio algoritmo de dígito verificador —
 * não é preciso CPF/CNPJ "real", só um que passe no cálculo de módulo 11 e não
 * seja sequência repetida.
 */

function protector(string $pepper = 'pepper-de-teste-nao-usar-em-producao'): DocumentProtector
{
    return new DocumentProtector($pepper);
}

describe('validação de CPF', function () {
    it('aceita CPF válido, com ou sem máscara', function () {
        expect(protector()->isValid('52998224725'))->toBeTrue()
            ->and(protector()->isValid('529.982.247-25'))->toBeTrue();
    });

    it('rejeita dígito verificador errado', function () {
        expect(protector()->isValid('52998224700'))->toBeFalse();
    });

    it('rejeita sequência repetida mesmo que passe no módulo 11', function () {
        expect(protector()->isValid('11111111111'))->toBeFalse();
    });

    it('identifica o tipo como CPF', function () {
        expect(protector()->type('52998224725'))->toBe(DocumentType::CPF);
    });
});

describe('validação de CNPJ', function () {
    it('aceita CNPJ válido, com ou sem máscara', function () {
        expect(protector()->isValid('11222333000181'))->toBeTrue()
            ->and(protector()->isValid('11.222.333/0001-81'))->toBeTrue();
    });

    it('rejeita dígito verificador errado', function () {
        expect(protector()->isValid('11222333000100'))->toBeFalse();
    });

    it('identifica o tipo como CNPJ', function () {
        expect(protector()->type('11222333000181'))->toBe(DocumentType::CNPJ);
    });
});

describe('casos inválidos', function () {
    it('rejeita quantidade de dígitos que não é 11 nem 14', function () {
        expect(protector()->isValid('123456'))->toBeFalse()
            ->and(protector()->isValid('123456789012345'))->toBeFalse();
    });

    it('rejeita string vazia', function () {
        expect(protector()->isValid(''))->toBeFalse();
    });
});

describe('hash — dedup determinística', function () {
    it('mesmo documento produz sempre o mesmo hash', function () {
        $protector = protector();

        expect($protector->hash('529.982.247-25'))->toBe($protector->hash('52998224725'));
    });

    it('documentos diferentes produzem hashes diferentes', function () {
        $protector = protector();

        expect($protector->hash('52998224725'))->not->toBe($protector->hash('11144477735'));
    });

    it('peppers diferentes produzem hashes diferentes para o mesmo documento', function () {
        expect(protector('pepper-a')->hash('52998224725'))
            ->not->toBe(protector('pepper-b')->hash('52998224725'));
    });

    it('recusa pepper vazio — hash sem pepper é enumerável', function () {
        expect(fn () => new DocumentProtector(''))->toThrow(InvalidArgumentException::class);
    });
});

describe('máscara — apresentação, não segurança', function () {
    it('mascara CPF mostrando só os dois últimos dígitos', function () {
        expect(protector()->mask('52998224725'))->toBe('***.***.***-25');
    });

    it('mascara CNPJ mostrando só os dois últimos dígitos', function () {
        expect(protector()->mask('11222333000181'))->toBe('**.***.***/****-81');
    });
});
