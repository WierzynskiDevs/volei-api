<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Domain\Services;

use App\Modules\Organizers\Domain\Enums\DocumentType;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Proteção de CPF/CNPJ do organizador.
 *
 * Exceção deliberada e registrada ao CLAUDE.md §12 ("CPF não é coletado"): aquela
 * regra é minimização de dado de PARTICIPANTE (atleta). Aqui o titular é o
 * ORGANIZADOR, pessoa física ou jurídica operando um negócio, e o dado é exigido
 * pelo próprio gateway de pagamento para abrir a subconta que recebe dinheiro de
 * terceiro (docs/adr/0014-cpf-cnpj-do-organizador.md, docs/asaas.md §8).
 *
 * Mesmo modelo do `PhoneProtector` (Users\Domain\Services), não reaproveitado
 * daquele módulo de propósito — módulo não importa Domain de outro módulo
 * (CLAUDE.md §4.1); a duplicação de ~30 linhas é o preço da fronteira.
 *
 *   - document_number_encrypted → reversível, exibido só a quem tem direito.
 *   - document_number_hash      → HMAC determinístico com pepper próprio,
 *                                  usado para impedir duas contas com o mesmo
 *                                  documento (nunca em claro como chave).
 */
final readonly class DocumentProtector
{
    public function __construct(
        #[SensitiveParameter] private string $pepper,
    ) {
        if ($this->pepper === '') {
            throw new InvalidArgumentException(
                'DOCUMENT_HASH_PEPPER não configurado. Sem pepper, o hash de CPF/CNPJ é reversível por força bruta.'
            );
        }
    }

    /** Remove máscara. Lança se o resultado não tiver 11 (CPF) ou 14 (CNPJ) dígitos. */
    public function normalize(string $document): string
    {
        $digits = preg_replace('/\D/', '', $document) ?? '';

        if (strlen($digits) !== 11 && strlen($digits) !== 14) {
            throw new InvalidArgumentException('CPF ou CNPJ com quantidade de dígitos inválida.');
        }

        return $digits;
    }

    public function type(string $document): DocumentType
    {
        return strlen($this->normalize($document)) === 11 ? DocumentType::CPF : DocumentType::CNPJ;
    }

    /**
     * Valida o dígito verificador. Sequências repetidas (`11111111111`) passam
     * no cálculo de módulo 11 mas nunca são documento real — rejeitadas à parte,
     * é o mesmo cuidado que qualquer validador de CPF/CNPJ de produção tem.
     */
    public function isValid(string $document): bool
    {
        try {
            $digits = $this->normalize($document);
        } catch (InvalidArgumentException) {
            return false;
        }

        if (preg_match('/^(\d)\1*$/', $digits) === 1) {
            return false;
        }

        return strlen($digits) === 11 ? $this->isValidCpf($digits) : $this->isValidCnpj($digits);
    }

    public function hash(string $document): string
    {
        return hash_hmac('sha256', $this->normalize($document), $this->pepper);
    }

    /**
     * Máscara para exibição a quem NÃO tem direito ao valor completo
     * (CLAUDE.md §12: mascarar no cliente não protege nada).
     */
    public function mask(string $document): string
    {
        $digits = $this->normalize($document);
        $last = substr($digits, -2);

        return $this->type($document) === DocumentType::CPF
            ? '***.***.**'.'*-'.$last
            : '**.***.***/****-'.$last;
    }

    private function isValidCpf(string $cpf): bool
    {
        for ($position = 9; $position <= 10; $position++) {
            $sum = 0;

            for ($i = 0; $i < $position; $i++) {
                $sum += (int) $cpf[$i] * ($position + 1 - $i);
            }

            $digit = ($sum * 10) % 11;
            $digit = $digit === 10 ? 0 : $digit;

            if ($digit !== (int) $cpf[$position]) {
                return false;
            }
        }

        return true;
    }

    private function isValidCnpj(string $cnpj): bool
    {
        $weightsFirst = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $weightsSecond = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        foreach ([[12, $weightsFirst], [13, $weightsSecond]] as [$position, $weights]) {
            $sum = 0;

            for ($i = 0; $i < $position; $i++) {
                $sum += (int) $cnpj[$i] * $weights[$i];
            }

            $remainder = $sum % 11;
            $digit = $remainder < 2 ? 0 : 11 - $remainder;

            if ($digit !== (int) $cnpj[$position]) {
                return false;
            }
        }

        return true;
    }
}
