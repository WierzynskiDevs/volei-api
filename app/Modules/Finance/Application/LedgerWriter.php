<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\LedgerEntryType;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Escrita do ledger (CLAUDE.md §9 e §7.9).
 *
 * Ponto único onde `ledger_entries` é gravado. Concentrar aqui é o que permite
 * garantir três coisas de uma vez: sinal correto por tipo, taxa congelada
 * copiada do pagamento, e idempotência.
 *
 * ## Idempotência sem depender de sorte
 *
 * Um webhook reentregue chamaria `recordConfirmedPayment()` de novo. O índice
 * parcial `ledger_entries_once_per_payment` recusa o segundo insert dos quatro
 * tipos de lançamento de pagamento — porque a segunda chamada não é erro, é a
 * mesma verdade sendo afirmada duas vezes. Duplicar lançamento é que seria erro:
 * dobraria o faturamento.
 *
 * A absorção usa `insertOrIgnore` (`ON CONFLICT DO NOTHING`), e **não**
 * try/catch em volta de um insert. A diferença é essencial no PostgreSQL: um
 * statement que falha **aborta a transação inteira** (`SQLSTATE 25P02`), e
 * qualquer query seguinte falha com "current transaction is aborted". Capturar a
 * exceção e seguir funciona no MySQL e quebra aqui — a transação já morreu.
 * `ON CONFLICT DO NOTHING` nunca falha, então nada é abortado.
 *
 * `REFUND` e `CHARGEBACK` ficam fora dessa trava: podem ser vários por
 * pagamento (estorno parcial), e é por lançamento novo que se corrige — nunca
 * por update, que o banco recusa.
 */
final readonly class LedgerWriter
{
    /**
     * Lança a decomposição de um pagamento confirmado.
     *
     * Chamado na confirmação (`CONFIRMED`), não no recebimento: é aí que o
     * dinheiro passa a existir como fato. O `RECEIVED` posterior muda a
     * **disponibilidade**, não os valores — e por isso não gera lançamento novo.
     *
     * `ORGANIZER_NET` só é lançado quando a taxa do gateway é conhecida
     * (ADR 0009 §5). Sem ela, os três primeiros lançamentos entram e o líquido
     * fica para quando o Asaas informar — o que a reconciliação resolve.
     *
     * @return int quantidade de lançamentos efetivamente criados
     */
    public function recordConfirmedPayment(Payment $payment, CarbonImmutable $now): int
    {
        $breakdown = $payment->breakdown();
        $created = 0;

        $created += $this->write(
            $payment,
            LedgerEntryType::GROSS_PAYMENT,
            $breakdown->gross,
            $now,
        );

        if ($breakdown->platformFee->isPositive()) {
            $created += $this->write(
                $payment,
                LedgerEntryType::PLATFORM_FEE,
                $breakdown->platformFee,
                $now,
            );
        }

        $asaasFee = $breakdown->asaasFee;

        if ($asaasFee !== null && $asaasFee->isPositive()) {
            $created += $this->write($payment, LedgerEntryType::ASAAS_FEE, $asaasFee, $now);
        }

        $net = $breakdown->organizerNet();

        if ($net !== null) {
            $created += $this->write($payment, LedgerEntryType::ORGANIZER_NET, $net, $now);
        }

        return $created;
    }

    /**
     * Lança um estorno.
     *
     * Valor sempre positivo na entrada; o sinal negativo é aplicado aqui, pela
     * convenção do tipo. Quem chama não decide sinal.
     */
    public function recordRefund(Payment $payment, Money $amount, CarbonImmutable $now, string $reason): void
    {
        $this->write($payment, LedgerEntryType::REFUND, $amount, $now, ['reason' => $reason]);
    }

    public function recordChargeback(Payment $payment, Money $amount, CarbonImmutable $now): void
    {
        $this->write($payment, LedgerEntryType::CHARGEBACK, $amount, $now);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return int 1 se criou, 0 se já existia
     */
    private function write(
        Payment $payment,
        LedgerEntryType $type,
        Money $amount,
        CarbonImmutable $now,
        array $metadata = [],
    ): int {
        /*
         * O sinal vem do TIPO, não de quem chama. Passar valor já negativo por
         * engano viraria taxa positiva — e taxa positiva infla a receita da
         * plataforma na agregação.
         */
        $signed = $type->expectedSign() < 0
            ? -abs($amount->cents)
            : abs($amount->cents);

        /*
         * `insertOrIgnore` em vez de Eloquent `create()`: gera
         * `ON CONFLICT DO NOTHING`, que absorve a reentrega sem abortar a
         * transação. Devolve a contagem de linhas afetadas — 0 significa "já
         * estava lançado", que é sucesso, não falha.
         *
         * O UUID é gerado aqui porque o query builder não passa pelos hooks do
         * model. Aceitável: `ledger_entries` é append-only e não tem observers.
         */
        $affected = DB::table('ledger_entries')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'type' => $type->value,
            'amount_cents' => $signed,
            'payment_id' => $payment->id,
            'organizer_id' => $payment->organizer_id,
            'event_id' => $payment->event_id,
            // Taxa congelada também no lançamento: ele tem de ser conferível
            // sem depender da linha do pagamento (§7.5).
            'platform_fee_basis_points' => $payment->platform_fee_basis_points,
            'metadata' => json_encode($metadata + [
                'method' => $payment->method->value,
                'provider' => $payment->provider,
                'registration_id' => $payment->registration_id,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);

        return $affected;
    }
}
