<?php

declare(strict_types=1);

use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Infrastructure\Providers\AsaasPaymentProvider;
use Illuminate\Http\Client\Factory as HttpFactory;

/*
 * Tradução e segurança do AsaasPaymentProvider.
 *
 * Sem rede: só o parser e o verificador de webhook, que são funções puras sobre
 * entrada. O que fala HTTP é testado no fluxo de integração com o FakeProvider
 * (CLAUDE.md §22 — teste financeiro não depende de rede).
 *
 * Este arquivo cobre o que o FakeProvider NÃO pode cobrir: a verificação do
 * token, que é responsabilidade da implementação real.
 */

function asaasProvider(string $webhookToken = 'token-de-teste-com-mais-de-32-caracteres'): AsaasPaymentProvider
{
    return new AsaasPaymentProvider(
        http: app(HttpFactory::class),
        baseUrl: 'https://api-sandbox.asaas.com',
        apiKey: 'chave-de-teste',
        platformWalletId: 'wallet-da-plataforma',
        webhookToken: $webhookToken,
    );
}

describe('verificação do webhook (ADR 0009 §4)', function (): void {

    it('aceita o token correto no header asaas-access-token', function (): void {
        $provider = asaasProvider();

        expect($provider->verifyWebhook(
            ['asaas-access-token' => ['token-de-teste-com-mais-de-32-caracteres']],
            '{}',
        ))->toBeTrue();
    });

    it('recusa token errado', function (): void {
        expect(asaasProvider()->verifyWebhook(
            ['asaas-access-token' => ['token-errado']],
            '{}',
        ))->toBeFalse();
    });

    it('recusa quando o header não vem', function (): void {
        expect(asaasProvider()->verifyWebhook([], '{}'))->toBeFalse();
    });

    /*
     * Falhar FECHADO: sem segredo configurado, nada é aceito. O contrário
     * transformaria um ambiente mal configurado num endpoint público que
     * qualquer um poderia usar para confirmar pagamento.
     */
    it('recusa tudo quando o segredo não está configurado', function (): void {
        expect(asaasProvider(webhookToken: '')->verifyWebhook(
            ['asaas-access-token' => ['qualquer-coisa']],
            '{}',
        ))->toBeFalse();
    });

    it('encontra o header independente de caixa', function (): void {
        expect(asaasProvider()->verifyWebhook(
            ['Asaas-Access-Token' => ['token-de-teste-com-mais-de-32-caracteres']],
            '{}',
        ))->toBeTrue();
    });
});

describe('tradução de evento → status do domínio', function (): void {

    /*
     * A distinção central do ADR 0009 §2: confirmado ≠ recebido. Se este teste
     * quebrar, o dashboard passa a mostrar como disponível dinheiro que o
     * gateway libera 32 dias depois (cartão).
     */
    it('mapeia PAYMENT_CONFIRMED e PAYMENT_RECEIVED para estados DIFERENTES', function (): void {
        $provider = asaasProvider();

        $confirmed = $provider->parseWebhook([
            'id' => 'evt_1', 'event' => 'PAYMENT_CONFIRMED', 'payment' => ['id' => 'pay_1'],
        ]);
        $received = $provider->parseWebhook([
            'id' => 'evt_2', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_1'],
        ]);

        expect($confirmed?->resultingStatus)->toBe(PaymentStatus::CONFIRMED)
            ->and($received?->resultingStatus)->toBe(PaymentStatus::RECEIVED)
            ->and($confirmed?->resultingStatus)->not->toBe($received?->resultingStatus);

        // E o significado financeiro de cada um.
        expect($confirmed?->resultingStatus->moneyIsAvailable())->toBeFalse()
            ->and($received?->resultingStatus->moneyIsAvailable())->toBeTrue();
    });

    it('mapeia os eventos que mudam estado', function (string $eventType, PaymentStatus $expected): void {
        $event = asaasProvider()->parseWebhook([
            'id' => 'evt_x', 'event' => $eventType, 'payment' => ['id' => 'pay_1'],
        ]);

        expect($event?->resultingStatus)->toBe($expected);
    })->with([
        ['PAYMENT_OVERDUE', PaymentStatus::OVERDUE],
        ['PAYMENT_REFUNDED', PaymentStatus::REFUNDED],
        ['PAYMENT_PARTIALLY_REFUNDED', PaymentStatus::PARTIALLY_REFUNDED],
        ['PAYMENT_CHARGEBACK_REQUESTED', PaymentStatus::CHARGEBACK],
        ['PAYMENT_CREDIT_CARD_CAPTURE_REFUSED', PaymentStatus::FAILED],
        ['PAYMENT_REPROVED_BY_RISK_ANALYSIS', PaymentStatus::FAILED],
        ['PAYMENT_RECEIVED_IN_CASH', PaymentStatus::RECEIVED],
    ]);

    /*
     * Evento reconhecido SEM efeito é diferente de evento desconhecido: um vira
     * IGNORED, o outro pede atenção. `PAYMENT_REFUND_IN_PROGRESS` está aqui de
     * propósito — a doc é explícita que o estorno só conta quando fica `DONE`.
     */
    it('trata como sem efeito os eventos que não mudam nada', function (string $eventType): void {
        $event = asaasProvider()->parseWebhook([
            'id' => 'evt_x', 'event' => $eventType, 'payment' => ['id' => 'pay_1'],
        ]);

        expect($event)->not->toBeNull()
            ->and($event?->isNoOp())->toBeTrue();
    })->with([
        'PAYMENT_CREATED',
        'PAYMENT_UPDATED',
        'PAYMENT_AUTHORIZED',
        'PAYMENT_CHECKOUT_VIEWED',
        'PAYMENT_BANK_SLIP_VIEWED',
        'PAYMENT_REFUND_IN_PROGRESS',
        'PAYMENT_AWAITING_RISK_ANALYSIS',
    ]);

    /*
     * Sem `id` não há chave de idempotência — e sem ela a reentrega duplicaria
     * efeito. Recusar é a única opção segura.
     */
    it('recusa payload sem id de evento', function (array $payload): void {
        expect(asaasProvider()->parseWebhook($payload))->toBeNull();
    })->with([
        'sem id' => [['event' => 'PAYMENT_CONFIRMED']],
        'id vazio' => [['id' => '', 'event' => 'PAYMENT_CONFIRMED']],
        'sem event' => [['id' => 'evt_1']],
        'vazio' => [[]],
    ]);

    it('extrai a referência externa e o id da cobrança', function (): void {
        $event = asaasProvider()->parseWebhook([
            'id' => 'evt_1',
            'event' => 'PAYMENT_CONFIRMED',
            'payment' => ['id' => 'pay_123', 'externalReference' => 'ref-nossa'],
        ]);

        expect($event?->providerPaymentId)->toBe('pay_123')
            ->and($event?->externalReference)->toBe('ref-nossa')
            ->and($event?->providerEventType)->toBe('PAYMENT_CONFIRMED');
    });
});

describe('taxa do gateway — nunca heurística (ADR 0004, ADR 0009 §5)', function (): void {

    it('deriva a taxa de value − netValue', function (): void {
        $event = asaasProvider()->parseWebhook([
            'id' => 'evt_1',
            'event' => 'PAYMENT_CONFIRMED',
            'payment' => ['id' => 'pay_1', 'value' => 130.00, 'netValue' => 128.01],
        ]);

        // 13000 − 12801 = 199
        expect($event?->gatewayFee?->cents)->toBe(199);
    });

    /*
     * Sem `netValue` a taxa é DESCONHECIDA. Reproduzir a heurística do
     * protótipo (PIX R$ 1,99) como se fosse verdade é proibido.
     */
    it('devolve null quando o Asaas não informa netValue', function (): void {
        $event = asaasProvider()->parseWebhook([
            'id' => 'evt_1',
            'event' => 'PAYMENT_CONFIRMED',
            'payment' => ['id' => 'pay_1', 'value' => 130.00],
        ]);

        expect($event?->gatewayFee)->toBeNull();
    });

    it('devolve null se o líquido vier maior que o bruto', function (): void {
        $event = asaasProvider()->parseWebhook([
            'id' => 'evt_1',
            'event' => 'PAYMENT_CONFIRMED',
            'payment' => ['id' => 'pay_1', 'value' => 100.00, 'netValue' => 120.00],
        ]);

        // Prefere desconhecido a gravar taxa negativa.
        expect($event?->gatewayFee)->toBeNull();
    });

    /*
     * Conversão via string, nunca via float: `13000 / 100` em ponto flutuante é
     * o caminho para pagar R$ 129,99.
     */
    it('converte valores decimais sem perder centavo', function (float $value, float $net, int $expectedFee): void {
        $event = asaasProvider()->parseWebhook([
            'id' => 'evt_1',
            'event' => 'PAYMENT_CONFIRMED',
            'payment' => ['id' => 'pay_1', 'value' => $value, 'netValue' => $net],
        ]);

        expect($event?->gatewayFee?->cents)->toBe($expectedFee);
    })->with([
        [130.00, 128.01, 199],
        [0.10, 0.09, 1],
        [999.99, 970.10, 2989],
        [45.50, 43.51, 199],
    ]);
});
