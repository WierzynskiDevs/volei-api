<?php

declare(strict_types=1);

/*
 * Configuração de domínio do SAQUE.
 *
 * Regra do CLAUDE.md §7.8: taxa da plataforma NUNCA é hardcoded em código de
 * domínio. A taxa efetiva vem do plano do organizador (tabela `plans`); os
 * valores aqui são apenas o padrão de provisionamento inicial e o fallback
 * para instalação nova.
 */

return [

    /*
     * Fuso de apresentação. A persistência é sempre UTC (CLAUDE.md §6).
     * Cada evento carrega o próprio timezone (docs/OPEN-QUESTIONS.md Q5).
     */
    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'America/Sao_Paulo'),

    /*
     * Versões vigentes dos documentos aceitos no cadastro. Mudar a versão aqui
     * faz com que novos aceites sejam gravados com o novo número — os aceites
     * antigos permanecem com a versão que valia na época (evidência de consentimento).
     */
    'terms_version' => env('SAQUE_TERMS_VERSION', '1.0'),
    'privacy_version' => env('SAQUE_PRIVACY_VERSION', '2.0'),

    /*
     * Plano padrão de um organizador recém-criado.
     */
    'default_plan_code' => env('SAQUE_DEFAULT_PLAN_CODE', 'FREE'),

    'payments' => [
        /*
         * fake  => FakePaymentProvider (ADR 0004) — desenvolvimento e testes.
         * asaas => integração real, só após consulta à documentação oficial.
         */
        'provider' => env('PAYMENT_PROVIDER', 'fake'),

        /*
         * Janela de reserva de vaga durante o checkout (ADR 0003).
         * A vaga só é ocupada em definitivo na confirmação do pagamento, mas
         * fica reservada por este período para não haver overbooking.
         */
        'reservation_ttl_minutes' => (int) env('SAQUE_RESERVATION_TTL_MINUTES', 60),

        /*
         * Asaas (ADR 0009, docs/asaas.md).
         *
         * Nenhum default de credencial e nenhum default de base: instalação sem
         * configurar não fala com gateway. A base de produção NÃO apareceu na
         * doc consultada, então é obrigatória em env — errar a base significa
         * cobrar no ambiente errado.
         */
        'asaas' => [
            'base_url' => env('ASAAS_BASE_URL', ''),
            'api_key' => env('ASAAS_API_KEY', ''),

            /*
             * Carteira da plataforma, destino do split da taxa. O split usa
             * `fixedValue` calculado por nós — nunca percentual, que incidiria
             * sobre o líquido (ADR 0009 §1).
             */
            'platform_wallet_id' => env('ASAAS_PLATFORM_WALLET_ID', ''),

            /*
             * Token compartilhado do webhook, enviado pelo Asaas no header
             * `asaas-access-token`. A doc exige 32–255 caracteres. Comparado em
             * tempo constante e nunca logado (§21).
             */
            'webhook_token' => env('ASAAS_WEBHOOK_TOKEN', ''),

            'timeout_seconds' => (int) env('ASAAS_TIMEOUT_SECONDS', 20),
        ],
    ],

    'lgpd' => [
        /*
         * Pepper do HMAC de telefone. Obrigatório: sem ele o hash de um
         * telefone brasileiro é enumerável por força bruta.
         */
        'phone_hash_pepper' => env('PHONE_HASH_PEPPER', ''),

        /*
         * Pepper do HMAC de CPF/CNPJ do organizador (docs/adr/0014). Pepper
         * próprio, separado do de telefone — rotacionar um não deve forçar
         * re-hash do outro.
         */
        'document_hash_pepper' => env('DOCUMENT_HASH_PEPPER', ''),

        /*
         * Pepper do HMAC de telefone do juiz (ADR 0013 §6). Pepper próprio:
         * dedup é por evento, não precisa (e não deve) compartilhar espaço de
         * hash com o telefone de usuário.
         */
        'referee_phone_hash_pepper' => env('REFEREE_PHONE_HASH_PEPPER', ''),
    ],

];
