<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\Exceptions\PaymentProviderException;
use App\Modules\Payments\Domain\PaymentProviderInterface;
use App\Modules\Payments\Infrastructure\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Reconciliação de pagamentos contra o gateway (CLAUDE.md §14).
 *
 * Exigência literal: *"job que compara estado local × estado do gateway e alerta
 * divergência. Webhook perdido não pode significar dinheiro perdido."*
 *
 * ## Por que isto não é opcional
 *
 * A doc do Asaas diz que, após **15 falhas consecutivas, a fila de webhooks pode
 * ser interrompida**, e que eventos ficam retidos por **14 dias**
 * (docs/asaas.md §4). Ou seja: existe um cenário documentado em que o gateway
 * simplesmente para de avisar. Sem reconciliação, um atleta pagaria e ficaria
 * sem vaga — e ninguém saberia.
 *
 * ## O que ela olha
 *
 * Só cobranças **abertas** (`DRAFT`, `PENDING`, `OVERDUE`): são as únicas em que
 * o estado pode ter avançado sem nós sabermos. Reconsultar pagamento já
 * confirmado gastaria quota (25.000 requisições/12h) sem ganho.
 *
 * ## Divergência é alerta, não correção silenciosa
 *
 * Quando o gateway diz algo diferente do que temos, a action **aplica** o estado
 * do gateway (ele é a fonte de verdade, §14) e **registra o alerta**. As duas
 * coisas: aplicar sem alertar esconderia que o webhook está falhando.
 */
final readonly class ReconcilePaymentsAction
{
    public function __construct(
        private PaymentProviderInterface $provider,
        private ApplyPaymentStatusAction $applyStatus,
    ) {}

    /**
     * @return array{checked: int, diverged: int, failed: int}
     */
    public function execute(CarbonImmutable $now, int $limit = 200): array
    {
        $checked = 0;
        $diverged = 0;
        $failed = 0;

        $payments = Payment::query()
            ->open()
            ->whereNotNull('provider_payment_id')
            // Mais antigas primeiro: quem está esperando há mais tempo é quem
            // corre mais risco de ter perdido o webhook.
            ->orderByRaw('reconciled_at NULLS FIRST')
            ->limit($limit)
            ->get();

        foreach ($payments as $payment) {
            $checked++;

            try {
                $snapshot = $this->provider->fetchCharge((string) $payment->provider_payment_id);
            } catch (PaymentProviderException $e) {
                $failed++;

                Log::channel('audit')->error('Reconciliação falhou ao consultar o gateway', [
                    'payment_id' => $payment->id,
                    'provider_payment_id' => $payment->provider_payment_id,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            if ($snapshot->status === $payment->status) {
                // Em dia. Marca a checagem para não reconsultar em seguida.
                $payment->reconciled_at = $now;
                $payment->save();

                continue;
            }

            $outcome = $this->applyStatus->execute(
                payment: $payment,
                target: $snapshot->status,
                now: $now,
                gatewayFee: $snapshot->gatewayFee,
                refundedTotal: $snapshot->refundedTotal,
            );

            if ($outcome === AppliedStatus::APPLIED) {
                $diverged++;

                /*
                 * Este log é o sinal de que o webhook não chegou. Divergência
                 * recorrente aqui significa fila do gateway com problema — é a
                 * métrica de "divergências de reconciliação" do §21.
                 */
                Log::channel('audit')->warning('Divergência corrigida pela reconciliação', [
                    'payment_id' => $payment->id,
                    'local_status_before' => $payment->getOriginal('status'),
                    'gateway_status' => $snapshot->status->value,
                    'registration_id' => $payment->registration_id,
                ]);
            }
        }

        return ['checked' => $checked, 'diverged' => $diverged, 'failed' => $failed];
    }
}
