<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Providers;

use App\Modules\Payments\Domain\DTO\AccountOnboardingRequest;
use App\Modules\Payments\Domain\DTO\AccountSnapshot;
use App\Modules\Payments\Domain\DTO\AccountStatusSnapshot;
use App\Modules\Payments\Domain\PaymentAccountProviderInterface;
use Illuminate\Support\Str;

/**
 * Provedor determinístico para desenvolvimento e testes (ADR 0018).
 *
 * `fetchAccountStatus()` não aprova sozinho — em produção, aprovação depende
 * de análise humana do Asaas (documento/KYC), então o fake também exige um
 * gatilho explícito. `simulateApproval()` existe só para teste controlar o
 * cenário, mesmo espírito de `FakePaymentProvider::webhookBody()`.
 */
final class FakePaymentAccountProvider implements PaymentAccountProviderInterface
{
    private static bool $approveOnFetch = false;

    public function name(): string
    {
        return 'fake';
    }

    public function createAccount(AccountOnboardingRequest $request): AccountSnapshot
    {
        return new AccountSnapshot(
            externalAccountId: 'fake_acc_'.Str::lower(Str::random(20)),
            apiKey: 'fake_key_'.Str::lower(Str::random(32)),
            walletId: 'fake_wallet_'.Str::lower(Str::random(20)),
        );
    }

    public function fetchAccountStatus(string $externalAccountId, string $accountApiKey): AccountStatusSnapshot
    {
        return new AccountStatusSnapshot(
            approved: self::$approveOnFetch,
            rawStatus: self::$approveOnFetch ? 'APPROVED' : 'PENDING',
        );
    }

    /** Só para teste — controla o que a próxima `fetchAccountStatus()` devolve. */
    public static function simulateApproval(bool $approved = true): void
    {
        self::$approveOnFetch = $approved;
    }

    public static function reset(): void
    {
        self::$approveOnFetch = false;
    }
}
