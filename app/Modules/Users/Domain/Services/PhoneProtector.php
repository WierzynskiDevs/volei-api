<?php

declare(strict_types=1);

namespace App\Modules\Users\Domain\Services;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Proteção de telefone exigida pela LGPD (BRIEF §16, CLAUDE.md §12).
 *
 * Modelo:
 *   - phone_encrypted → valor reversível, usado quando o telefone precisa ser exibido
 *                       a quem tem direito (ex.: o próprio titular).
 *   - phone_hash      → HMAC determinístico com pepper, usado para unicidade,
 *                       busca e deduplicação.
 *
 * PROIBIDO usar o telefone em claro como chave de consulta, índice ou parâmetro
 * de URL. Toda busca por telefone passa pelo hash.
 *
 * O pepper vive em PHONE_HASH_PEPPER (env). Rotacioná-lo invalida a deduplicação
 * existente e exige plano de re-hash — não é uma chave descartável.
 */
final readonly class PhoneProtector
{
    public function __construct(
        #[SensitiveParameter] private string $pepper,
    ) {
        if ($this->pepper === '') {
            throw new InvalidArgumentException(
                'PHONE_HASH_PEPPER não configurado. Sem pepper, o hash de telefone é reversível por força bruta.'
            );
        }
    }

    /**
     * Normaliza para E.164 brasileiro antes de qualquer hash.
     *
     * Sem normalização, "(41) 98888-4477", "41988884477" e "+5541988884477"
     * gerariam hashes distintos e a deduplicação não funcionaria.
     */
    public function normalize(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if ($digits === '') {
            throw new InvalidArgumentException('Telefone vazio.');
        }

        // Remove o prefixo internacional do Brasil, se veio.
        if (str_starts_with($digits, '55') && strlen($digits) > 11) {
            $digits = substr($digits, 2);
        }

        // Remove zero de operadora/tronco.
        $digits = ltrim($digits, '0');

        // DDD (2) + número (8 fixo ou 9 móvel).
        if (! preg_match('/^[1-9]\d{9,10}$/', $digits)) {
            throw new InvalidArgumentException('Telefone brasileiro inválido.');
        }

        return '+55'.$digits;
    }

    /**
     * HMAC-SHA256 determinístico. Determinístico é requisito: o hash é a chave
     * de unicidade e de busca. Por isso o pepper é obrigatório — sem ele, o
     * espaço de telefones brasileiros é pequeno o bastante para ser enumerado.
     */
    public function hash(string $phone): string
    {
        return hash_hmac('sha256', $this->normalize($phone), $this->pepper);
    }

    /**
     * Máscara para exibição a quem NÃO tem direito ao valor completo.
     *
     * CLAUDE.md §12: o backend não envia o dado completo quando o solicitante
     * não tem direito a ele — mascarar no cliente não protege nada.
     */
    public function mask(string $phone): string
    {
        $normalized = $this->normalize($phone);
        $last = substr($normalized, -4);

        return '+55 •••••'.$last;
    }
}
