<?php

declare(strict_types=1);

use App\Shared\Domain\Exceptions\MoneyException;
use App\Shared\Domain\FeeRate;
use App\Shared\Domain\Money;

describe('Money — construção', function () {
    it('guarda centavos como inteiro', function () {
        expect(Money::fromCents(13000)->cents)->toBe(13000);
    });

    it('converte reais para centavos sem passar por float', function (string $input, int $expected) {
        expect(Money::fromReais($input)->cents)->toBe($expected);
    })->with([
        ['130', 13000],
        ['130.00', 13000],
        ['130,00', 13000],   // vírgula, formato brasileiro
        ['0.01', 1],
        ['0', 0],
        ['1.5', 150],        // uma casa decimal
        ['-10.50', -1050],
    ]);

    it('rejeita valor com mais de duas casas decimais', function () {
        Money::fromReais('10.999');
    })->throws(MoneyException::class);

    it('rejeita texto que não é número', function () {
        Money::fromReais('R$ 130,00');
    })->throws(MoneyException::class);

    it('não perde precisão em valores grandes', function () {
        // Falha clássica de float: 0.1 + 0.2 !== 0.3
        $a = Money::fromReais('0.1');
        $b = Money::fromReais('0.2');
        expect($a->add($b)->cents)->toBe(30);
    });
});

describe('Money — aritmética', function () {
    it('soma e subtrai', function () {
        $a = Money::fromCents(13000);
        $b = Money::fromCents(650);

        expect($a->add($b)->cents)->toBe(13650)
            ->and($a->subtract($b)->cents)->toBe(12350);
    });

    it('é imutável', function () {
        $a = Money::fromCents(100);
        $a->add(Money::fromCents(50));

        expect($a->cents)->toBe(100);
    });
});

describe('Money — percentual (taxa da plataforma)', function () {
    it('calcula o exemplo do brief: 5% de R$130,00 = R$6,50', function () {
        $gross = Money::fromCents(13000);
        $fee = $gross->percentage(FeeRate::fromPercent('5'));

        expect($fee->cents)->toBe(650);
    });

    it('calcula as taxas dos três planos', function (string $percent, int $expected) {
        expect(Money::fromCents(13000)->percentage(FeeRate::fromPercent($percent))->cents)
            ->toBe($expected);
    })->with([
        ['5', 650],     // FREE
        ['3.5', 455],   // PRO
        ['2.5', 325],   // PREMIUM
    ]);

    it('arredonda half-up no centavo', function (int $cents, string $rate, int $expected) {
        expect(Money::fromCents($cents)->percentage(FeeRate::fromPercent($rate))->cents)
            ->toBe($expected);
    })->with([
        // 333 * 5% = 16,65 centavos -> 17 (half-up)
        [333, '5', 17],
        // 310 * 5% = 15,5 centavos -> 16 (empate sobe)
        [310, '5', 16],
        // 210 * 5% = 10,5 centavos -> 11 (empate sobe)
        [210, '5', 11],
        // 100 * 3,5% = 3,5 centavos -> 4
        [100, '3.5', 4],
    ]);

    it('taxa zero devolve zero', function () {
        expect(Money::fromCents(13000)->percentage(FeeRate::zero())->cents)->toBe(0);
    });
});

describe('Money — invariantes', function () {
    it('bloqueia valor negativo onde não é permitido', function () {
        Money::fromCents(-1)->assertNotNegative('registration_fee');
    })->throws(MoneyException::class);

    it('permite valor zero', function () {
        expect(Money::zero()->assertNotNegative('registration_fee')->cents)->toBe(0);
    });
});

describe('Money — decomposição financeira do brief §29', function () {
    it('bruto - taxa plataforma - taxa asaas = líquido do organizador', function () {
        $gross = Money::fromCents(13000);
        $platformFee = $gross->percentage(FeeRate::fromPercent('5'));  // 650
        $asaasFee = Money::fromCents(199);                              // PIX

        $net = $gross->subtract($platformFee)->subtract($asaasFee);

        expect($platformFee->cents)->toBe(650)
            ->and($net->cents)->toBe(12151)
            // A soma das partes tem de fechar exatamente com o bruto (CLAUDE.md §7.4)
            ->and($net->add($platformFee)->add($asaasFee)->equals($gross))->toBeTrue();
    });
});
