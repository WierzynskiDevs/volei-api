<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Organizers\Domain\Enums\PaymentAccountStatus;
use App\Modules\Organizers\Domain\Exceptions\PaymentAccountAlreadyRequestedException;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Payments\Domain\DTO\AccountOnboardingRequest;
use App\Modules\Payments\Domain\PaymentAccountProviderInterface;
use App\Modules\Users\Infrastructure\Models\User;
use App\Shared\Domain\Money;

/**
 * Abre a subconta do organizador no gateway (ADR 0018).
 *
 * Chamada HTTP ao provedor acontece fora de transação (CLAUDE.md §8) — não
 * há nada para "reservar" antes: abrir conta não é operação que faça sentido
 * deixar pendente em caso de falha, diferente de cobrança. Falha na chamada
 * deixa o organizador exatamente como estava (`NOT_LINKED`), nunca em estado
 * intermediário inconsistente.
 */
final readonly class RequestPaymentAccountOnboardingAction
{
    public function __construct(
        private PaymentAccountProviderInterface $provider,
        private AuditLogger $audit,
    ) {}

    public function execute(
        Organizer $organizer,
        User $actor,
        string $mobilePhone,
        int $monthlyIncomeCents,
        string $address,
        string $addressNumber,
        string $province,
        string $postalCode,
    ): Organizer {
        if ($organizer->payment_account_status !== PaymentAccountStatus::NOT_LINKED) {
            throw PaymentAccountAlreadyRequestedException::create();
        }

        $snapshot = $this->provider->createAccount(new AccountOnboardingRequest(
            name: $organizer->name,
            email: (string) $organizer->contact_email,
            documentNumber: (string) $organizer->document_number_encrypted,
            mobilePhone: $mobilePhone,
            monthlyIncome: Money::fromCents($monthlyIncomeCents),
            address: $address,
            addressNumber: $addressNumber,
            province: $province,
            postalCode: $postalCode,
            externalReference: $organizer->id,
        ));

        $organizer->payment_account_external_id = $snapshot->externalAccountId;
        $organizer->payment_account_api_key_encrypted = $snapshot->apiKey;
        $organizer->payment_account_wallet_id = $snapshot->walletId;
        $organizer->payment_account_mobile_phone_encrypted = $mobilePhone;
        $organizer->payment_account_address = $address;
        $organizer->payment_account_address_number = $addressNumber;
        $organizer->payment_account_province = $province;
        $organizer->payment_account_postal_code = $postalCode;
        $organizer->payment_account_income_cents = $monthlyIncomeCents;
        $organizer->payment_account_status = PaymentAccountStatus::PENDING;
        $organizer->save();

        $this->audit->log(
            action: AuditAction::ORGANIZER_PAYMENT_ACCOUNT_REQUESTED,
            actor: $actor,
            targetType: 'organizer',
            targetId: $organizer->id,
            // Nunca o apiKey/walletId aqui, mesmo criptografado (CLAUDE.md §21).
            metadata: ['provider' => $this->provider->name()],
        );

        return $organizer;
    }
}
