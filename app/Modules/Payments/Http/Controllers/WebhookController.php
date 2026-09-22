<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Application\Jobs\ProcessWebhookEventJob;
use App\Modules\Payments\Application\RecordWebhookEventAction;
use App\Modules\Payments\Domain\PaymentProviderInterface;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `POST /api/v1/webhooks/asaas` (CLAUDE.md §14).
 *
 * Rota **pública** por natureza — o gateway não tem sessão. A autenticação é o
 * token compartilhado no header `asaas-access-token`, verificado pelo provider.
 *
 * ## O que este controller faz, em ordem
 *
 * 1. verifica autenticidade;
 * 2. normaliza o payload;
 * 3. **persiste** o evento;
 * 4. enfileira o processamento;
 * 5. responde 200.
 *
 * Responder 200 antes de persistir perderia o evento em caso de queda.
 * Processar antes de responder atrasaria a resposta e a doc do Asaas penaliza a
 * fila em caso de lentidão/erro — 15 falhas consecutivas podem interrompê-la.
 *
 * ## Por que quase tudo devolve 200
 *
 * Reentrega, evento desconhecido e payload sem efeito devolvem 200 de propósito:
 * são comportamento normal do gateway, e devolver erro só faria a fila dele ser
 * penalizada por algo que não é problema. **Token inválido** devolve 401, que é
 * o único caso em que a requisição não deveria ter chegado.
 */
final class WebhookController
{
    public function asaas(
        Request $request,
        PaymentProviderInterface $provider,
        RecordWebhookEventAction $record,
    ): JsonResponse {
        if (! $provider->verifyWebhook($request->headers->all(), $request->getContent())) {
            /*
             * Log sem o token e sem o corpo: o corpo de um webhook de pagamento
             * carrega dado do pagador (§21). IP e tipo bastam para investigar.
             */
            Log::channel('audit')->warning('Webhook de pagamento recusado: token inválido', [
                'ip' => $request->ip(),
                'provider' => $provider->name(),
            ]);

            return response()->json(
                ['error' => ['code' => 'WEBHOOK_UNAUTHORIZED', 'message' => 'Requisição não autorizada.']],
                401,
            );
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $event = $provider->parseWebhook($payload);

        if ($event === null) {
            /*
             * Corpo não reconhecível. 200 porque não há o que o gateway possa
             * fazer diferente — e retentar não mudaria o payload.
             */
            Log::channel('audit')->warning('Webhook de pagamento não reconhecido', [
                'provider' => $provider->name(),
                'keys' => array_keys($payload),
            ]);

            return response()->json(['received' => true, 'processed' => false]);
        }

        [$stored, $isNew] = $record->execute(
            provider: $provider->name(),
            event: $event,
            rawPayload: $payload,
            now: CarbonImmutable::now(),
        );

        /*
         * Só enfileira o que é novo. Reentrega do mesmo evento é absorvida sem
         * novo efeito (CLAUDE.md §8) — enfileirar de novo seria inofensivo pela
         * idempotência do job, mas gastaria fila por nada.
         */
        if ($isNew) {
            ProcessWebhookEventJob::dispatch($stored->id);
        }

        return response()->json([
            'received' => true,
            'duplicate' => ! $isNew,
        ]);
    }
}
