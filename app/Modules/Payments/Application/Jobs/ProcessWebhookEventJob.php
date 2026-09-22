<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Jobs;

use App\Modules\Payments\Application\ApplyPaymentStatusAction;
use App\Modules\Payments\Domain\Enums\WebhookProcessingStatus;
use App\Modules\Payments\Domain\PaymentProviderInterface;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Payments\Infrastructure\Models\PaymentWebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processa um evento de webhook já persistido (CLAUDE.md §14 e §20).
 *
 * Assíncrono de propósito: o controller responde 200 assim que grava, porque a
 * doc do Asaas pede resposta rápida e penaliza a fila em caso de falha. O
 * trabalho real acontece aqui.
 *
 * ## Idempotência
 *
 * `ShouldBeUnique` pelo id do evento evita dois workers processando o mesmo
 * registro. Mas a garantia de verdade não depende disso: `ApplyPaymentStatusAction`
 * é idempotente e o índice do ledger recusa lançamento duplicado. A unicidade
 * aqui é otimização, não a linha de defesa (§20: job financeiro é idempotente e
 * nunca duplica efeito no retry).
 */
final class ProcessWebhookEventJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Retry com backoff; falha final vai para `failed_jobs` e alerta (§20). */
    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 120, 300];

    public function __construct(public readonly string $webhookEventId) {}

    public function uniqueId(): string
    {
        return $this->webhookEventId;
    }

    public function handle(
        PaymentProviderInterface $provider,
        ApplyPaymentStatusAction $applyStatus,
    ): void {
        $record = PaymentWebhookEvent::query()->find($this->webhookEventId);

        if (! $record instanceof PaymentWebhookEvent) {
            return;
        }

        // Já concluído: reentrega do job, nada a fazer.
        if ($record->processing_status->isDone()) {
            return;
        }

        $record->processing_status = WebhookProcessingStatus::PROCESSING;
        $record->attempts = $record->attempts + 1;
        $record->save();

        try {
            $this->process($record, $provider, $applyStatus);
        } catch (Throwable $e) {
            /*
             * Falha NÃO perde o evento: fica em FAILED com a mensagem, para
             * reprocessamento e alerta. É a exigência literal do §14 — "falha de
             * processamento não perde o evento".
             */
            $record->processing_status = WebhookProcessingStatus::FAILED;
            $record->error_message = mb_substr($e->getMessage(), 0, 2000);
            $record->save();

            Log::channel('audit')->error('Falha ao processar webhook de pagamento', [
                'webhook_event_id' => $record->id,
                'provider_event_id' => $record->provider_event_id,
                'provider_event_type' => $record->provider_event_type,
                'attempts' => $record->attempts,
                'exception' => $e->getMessage(),
            ]);

            throw $e;   // deixa o retry da fila agir
        }
    }

    private function process(
        PaymentWebhookEvent $record,
        PaymentProviderInterface $provider,
        ApplyPaymentStatusAction $applyStatus,
    ): void {
        $now = CarbonImmutable::now();
        $event = $provider->parseWebhook($record->payload);

        if ($event === null) {
            // Corpo não reconhecível. Não é falha de processamento nossa —
            // marcar como ignorado mantém a trilha sem gerar alerta falso.
            $this->finish($record, WebhookProcessingStatus::IGNORED, $now, 'payload não reconhecível');

            return;
        }

        $target = $event->resultingStatus;

        if ($target === null) {
            // Evento reconhecido e sem efeito no domínio (ex.: checkout visto).
            $this->finish($record, WebhookProcessingStatus::IGNORED, $now);

            return;
        }

        $payment = $record->payment_id === null
            ? null
            : Payment::query()->find($record->payment_id);

        if (! $payment instanceof Payment) {
            /*
             * Evento sem pagamento correspondente. FAILED de propósito: pode ser
             * evento de outra integração na mesma conta, mas também pode ser
             * cobrança nossa que não gravou — e esse segundo caso é dinheiro
             * sem trilha, que precisa de olho humano.
             */
            $this->finish(
                $record,
                WebhookProcessingStatus::FAILED,
                $now,
                'nenhum pagamento local corresponde a este evento',
            );

            return;
        }

        $outcome = $applyStatus->execute(
            payment: $payment,
            target: $target,
            now: $now,
            gatewayFee: $event->gatewayFee,
            refundedTotal: $event->refundedTotal,
        );

        /*
         * Qualquer resultado aqui é sucesso de processamento — inclusive
         * OUT_OF_ORDER e UNCHANGED. Marcar fora de ordem como falha faria a
         * fila retentar para sempre um evento que já está superado.
         */
        $this->finish($record, WebhookProcessingStatus::PROCESSED, $now, $outcome->name);
    }

    private function finish(
        PaymentWebhookEvent $record,
        WebhookProcessingStatus $status,
        CarbonImmutable $now,
        ?string $note = null,
    ): void {
        $record->processing_status = $status;
        $record->processed_at = $now;
        $record->error_message = $note;
        $record->save();
    }
}
