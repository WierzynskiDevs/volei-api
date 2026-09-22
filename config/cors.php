<?php

declare(strict_types=1);

/*
 * CORS (CLAUDE.md §11, ADR 0005).
 *
 * A autenticação é por cookie httpOnly, o que torna esta configuração parte do
 * mecanismo de segurança e não um detalhe de infraestrutura:
 *
 *  - `supports_credentials` PRECISA ser true, senão o navegador não envia o
 *    cookie de sessão e nenhuma rota autenticada funciona.
 *  - Com credenciais habilitadas, `allowed_origins` NÃO pode ser `*` — a
 *    combinação é rejeitada pelo navegador e, se fosse aceita, permitiria que
 *    qualquer site fizesse requisições autenticadas em nome do usuário.
 *
 * Por isso a lista é uma allowlist explícita, vinda de env. Adicionar origem é
 * decisão consciente, não efeito colateral de configuração.
 */

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', (string) env('FRONTEND_URL', 'http://localhost:8080'))),
)));

return [

    // O endpoint de CSRF do Sanctum fica fora de /api e também precisa passar.
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        'Idempotency-Key',
    ],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 0,

    'supports_credentials' => true,

];
