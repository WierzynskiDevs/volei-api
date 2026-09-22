<?php

declare(strict_types=1);

use App\Modules\Payments\Domain\Exceptions\BreakdownDoesNotBalanceException;
use App\Modules\Payments\Domain\PaymentBreakdown;
use App\Shared\Domain\Exceptions\MoneyException;
use App\Shared\Domain\FeeRate;
use App\Shared\Domain\Money;

/*
 * Decomposição financeira (CLAUDE.md §7). Sem banco e sem rede — §22 exige que
 * cálculo financeiro seja testável sem infraestrutura.
 */

describe('PaymentBreakdown — cálculo da taxa da plataforma', function (): void {

    it('calcula o exemplo do brief: 5% de R$ 130,00 = R$ 6,50', function (): void {
        $breakdown = PaymentBreakdown::forCharge(
            gross: Money::fromCents(13000),
            platformFeeRate: FeeRate::fromPercent('5'),
            platformFeeFixed: Money::zero(),
        );

        expect($breakdown->platformFee->cents)->toBe(650);
    });

    it('soma a parte percentual com a parte fixa do plano', function (): void {
        // 5% de R$ 130 = R$ 6,50; mais R$ 1,00 fixo = R$ 7,50.
        $breakdown = PaymentBreakdown::forCharge(
            gross: Money::fromCents(13000),
            platformFeeRate: FeeRate::fromPercent('5'),
            platformFeeFixed: Money::fromCents(100),
        );

        expect($breakdown->platformFee->cents)->toBe(750);
    });

    /*
     * Sobre o BRUTO, não sobre o líquido. É a definição do §7.7 e a razão pela
     * qual o split no Asaas precisa ser `fixedValue` (ADR 0009 §1): o
     * `percentualValue` do gateway incidiria sobre o líquido e a taxa da
     * plataforma passaria a variar com a taxa do gateway.
     */
    it('calcula a taxa sobre o bruto, não sobre o líquido', function (): void {
        $semTaxaGateway = PaymentBreakdown::forCharge(
            gross: Money::fromCents(13000),
            platformFeeRate: FeeRate::fromPercent('5'),
            platformFeeFixed: Money::zero(),
        );

        $comTaxaGateway = PaymentBreakdown::forCharge(
            gross: Money::fromCents(13000),
            platformFeeRate: FeeRate::fromPercent('5'),
            platformFeeFixed: Money::zero(),
            asaasFee: Money::fromCents(199),
        );

        // A taxa da plataforma é a MESMA nos dois casos.
        expect($comTaxaGateway->platformFee->cents)
            ->toBe($semTaxaGateway->platformFee->cents)
            ->toBe(650);
    });

    it('taxa zero devolve taxa zero', function (): void {
        $breakdown = PaymentBreakdown::forCharge(
            gross: Money::fromCents(13000),
            platformFeeRate: FeeRate::zero(),
            platformFeeFixed: Money::zero(),
        );

        expect($breakdown->platformFee->isZero())->toBeTrue();
    });
});

describe('PaymentBreakdown — líquido do organizador', function (): void {

    it('fecha a identidade do §7.7: bruto − plataforma − gateway = líquido', function (): void {
        $breakdown = PaymentBreakdown::forCharge(
            gross: Money::fromCents(13000),
            platformFeeRate: FeeRate::fromPercent('5'),
            platformFeeFixed: Money::zero(),
            asaasFee: Money::fromCents(199),
        );

        // 13000 − 650 − 199 = 12151
        expect($breakdown->organizerNet()?->cents)->toBe(12151);
    });

    /*
     * O ponto mais importante deste arquivo. Sem a taxa do gateway o líquido é
     * DESCONHECIDO, não zero — e zero seria a afirmação falsa de que o
     * organizador não recebe nada (ADR 0009 §5).
     */
    it('devolve null — não zero — quando a taxa do gateway é desconhecida', function (): void {
        $breakdown = PaymentBreakdown::forCharge(
            gross: Money::fromCents(13000),
            platformFeeRate: FeeRate::fromPercent('5'),
            platformFeeFixed: Money::zero(),
        );

        expect($breakdown->organizerNet())->toBeNull()
            ->and($breakdown->asaasFee)->toBeNull();
    });

    it('preenche a taxa do gateway depois, sem recalcular a taxa da plataforma', function (): void {
        $antes = PaymentBreakdown::forCharge(
            gross: Money::fromCents(13000),
            platformFeeRate: FeeRate::fromPercent('5'),
            platformFeeFixed: Money::fromCents(100),
        );

        $depois = $antes->withAsaasFee(Money::fromCents(199));

        expect($depois->platformFee->cents)->toBe($antes->platformFee->cents)->toBe(750)
            ->and($depois->organizerNet()?->cents)->toBe(13000 - 750 - 199);
    });

    it('é imutável: preencher a taxa não altera o original', function (): void {
        $original = PaymentBreakdown::forCharge(
            gross: Money::fromCents(13000),
            platformFeeRate: FeeRate::fromPercent('5'),
            platformFeeFixed: Money::zero(),
        );

        $original->withAsaasFee(Money::fromCents(199));

        expect($original->organizerNet())->toBeNull();
    });
});

describe('PaymentBreakdown — invariante do §7.4', function (): void {

    /*
     * "Se gross − platform_fee − asaas_fee != net, a operação FALHA — não
     * corrige." Estes são os casos em que ela falha.
     */
    it('recusa quando a taxa da plataforma excede o bruto', function (): void {
        expect(fn (): PaymentBreakdown => PaymentBreakdown::forCharge(
            gross: Money::fromCents(100),
            platformFeeRate: FeeRate::zero(),
            platformFeeFixed: Money::fromCents(500),
        ))->toThrow(BreakdownDoesNotBalanceException::class);
    });

    /*
     * Cobrança de valor muito baixo é o caso real: R$ 1,00 de inscrição não
     * cobre taxa fixa da plataforma mais taxa do gateway. Falhar aqui evita
     * mandar ao Asaas um split que ele recusaria (ADR 0009 §1).
     */
    it('recusa quando as taxas somadas deixam o líquido negativo', function (): void {
        expect(fn (): PaymentBreakdown => PaymentBreakdown::forCharge(
            gross: Money::fromCents(100),
            platformFeeRate: FeeRate::fromPercent('5'),
            platformFeeFixed: Money::zero(),
            asaasFee: Money::fromCents(199),
        ))->toThrow(BreakdownDoesNotBalanceException::class);
    });

    it('devolve o código de erro estável e o contexto do desequilíbrio', function (): void {
        try {
            PaymentBreakdown::forCharge(
                gross: Money::fromCents(100),
                platformFeeRate: FeeRate::zero(),
                platformFeeFixed: Money::fromCents(500),
            );

            $this->fail('deveria ter lançado');
        } catch (BreakdownDoesNotBalanceException $e) {
            expect($e->errorCode())->toBe('PAYMENT_BREAKDOWN_UNBALANCED')
                ->and($e->context['reason'])->toBe('platform_fee_exceeds_gross')
                ->and($e->context['gross_cents'])->toBe(100);
        }
    });

    it('aceita líquido exatamente zero — cobre as taxas na conta exata', function (): void {
        $breakdown = PaymentBreakdown::forCharge(
            gross: Money::fromCents(300),
            platformFeeRate: FeeRate::zero(),
            platformFeeFixed: Money::fromCents(101),
            asaasFee: Money::fromCents(199),
        );

        expect($breakdown->organizerNet()?->isZero())->toBeTrue();
    });

    it('recusa taxa de gateway negativa', function (): void {
        expect(fn (): PaymentBreakdown => PaymentBreakdown::forCharge(
            gross: Money::fromCents(13000),
            platformFeeRate: FeeRate::fromPercent('5'),
            platformFeeFixed: Money::zero(),
            asaasFee: Money::fromCents(-100),
        ))->toThrow(MoneyException::class);
    });
});

describe('PaymentBreakdown — reconstrução do que está persistido', function (): void {

    /*
     * §7.5: a taxa é congelada. `fromPersisted` NÃO recalcula — se o plano
     * mudou de 5% para 3%, um pagamento antigo continua com os R$ 6,50 dele.
     */
    it('preserva a taxa gravada mesmo que não corresponda à alíquota', function (): void {
        $breakdown = PaymentBreakdown::fromPersisted(
            gross: Money::fromCents(13000),
            // Taxa gravada: R$ 6,50, de quando o plano era 5%.
            platformFee: Money::fromCents(650),
            // Alíquota atual do plano: 3%.
            platformFeeRate: FeeRate::fromPercent('3'),
            platformFeeFixed: Money::zero(),
            asaasFee: Money::fromCents(199),
        );

        // Continua R$ 6,50, e não os R$ 3,90 que 3% daria.
        expect($breakdown->platformFee->cents)->toBe(650)
            ->and($breakdown->organizerNet()?->cents)->toBe(12151);
    });

    it('valida a identidade também na reconstrução', function (): void {
        expect(fn (): PaymentBreakdown => PaymentBreakdown::fromPersisted(
            gross: Money::fromCents(100),
            platformFee: Money::fromCents(90),
            platformFeeRate: FeeRate::zero(),
            platformFeeFixed: Money::zero(),
            asaasFee: Money::fromCents(50),
        ))->toThrow(BreakdownDoesNotBalanceException::class);
    });
});
