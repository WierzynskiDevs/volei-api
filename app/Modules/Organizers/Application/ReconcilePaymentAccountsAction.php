<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Organizers\Domain\Enums\PaymentAccountStatus;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Payments\Domain\Exceptions\PaymentAccountProviderException;
use App\Modules\Payments\Domain\PaymentAccountProviderInterface;
use Illuminate\Support\Collection;

/**
 * Reconciliação periódica das subcontas pendentes (ADR 0018 §6).
 *
 * Webhook de conta não foi adotado nesta fatia — o catálogo de eventos não
 * está confirmado (`docs/asaas.md` §9). Polling é a rede de segurança que já
 * existe para pagamentos (`ReconcilePaymentsAction`), aplicada aqui ao mesmo
 * problema: saber se o gateway aprovou algo sem depender só de webhook.
 */
final readonly class ReconcilePaymentAccountsAction
{
    public function __construct(
        private PaymentAccountProviderInterface $provider,
        private AuditLogger $audit,
    ) {}

    /** @return array{checked: int, linked: int, failed: int} */
    public function execute(int $limit = 100): array
    {
        $pending = Organizer::query()
            ->where('payment_account_status', PaymentAccountStatus::PENDING)
            ->whereNotNull('payment_account_external_id')
            ->limit($limit)
            ->get();

        $linked = 0;
        $failed = 0;

        /** @var Collection<int, Organizer> $pending */
        foreach ($pending as $organizer) {
            $externalId = $organizer->payment_account_external_id;
            $apiKey = $organizer->payment_account_api_key_encrypted;

            if ($externalId === null || $apiKey === null) {
                continue; // dado corrompido: PENDING sem credencial não deveria existir.
            }

            try {
                $status = $this->provider->fetchAccountStatus($externalId, $apiKey);
            } catch (PaymentAccountProviderException) {
                $failed++;

                continue; // reprocessa na próxima janela — nunca perde o organizador de vista.
            }

            if (! $status->approved) {
                continue;
            }

            $organizer->payment_account_status = PaymentAccountStatus::LINKED;
            $organizer->save();

            $this->audit->log(
                action: AuditAction::ORGANIZER_PAYMENT_ACCOUNT_LINKED,
                actor: null,
                targetType: 'organizer',
                targetId: $organizer->id,
                metadata: ['provider' => $this->provider->name()],
            );

            $linked++;
        }

        return ['checked' => $pending->count(), 'linked' => $linked, 'failed' => $failed];
    }
}
