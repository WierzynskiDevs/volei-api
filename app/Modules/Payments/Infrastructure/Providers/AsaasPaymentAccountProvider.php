<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Providers;

use App\Modules\Payments\Domain\DTO\AccountOnboardingRequest;
use App\Modules\Payments\Domain\DTO\AccountSnapshot;
use App\Modules\Payments\Domain\DTO\AccountStatusSnapshot;
use App\Modules\Payments\Domain\Exceptions\PaymentAccountProviderException;
use App\Modules\Payments\Domain\PaymentAccountProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Integração Asaas — provisionamento de subconta (ADR 0018).
 *
 * Escrito a partir de `docs/asaas.md` §8 (doc oficial consultada em
 * 21/09/2026). `CLAUDE.md` §14 proíbe implementar de memória — o que a doc
 * não respondeu (estados intermediários, rotação de `apiKey`) está marcado
 * como lacuna lá e não virou suposição aqui.
 *
 * Única classe que conhece o vocabulário desta parte do Asaas
 * (`cpfCnpj`, `mobilePhone`, `incomeValue`) — nunca atravessa para `Domain/`.
 *
 * ⚠️ Não usar em produção: sandbox ainda não existe (ADR 0004, Q8), e sem
 * fluxo de documento/KYC (ADR 0018 §5) nenhuma subconta real chega a
 * aprovada mesmo com este código funcionando.
 */
final readonly class AsaasPaymentAccountProvider implements PaymentAccountProviderInterface
{
    public function __construct(
        private HttpFactory $http,
        private string $baseUrl,
        /** apiKey da conta RAIZ (a nossa) — é quem tem permissão de abrir subcontas. */
        private string $apiKey,
        private int $timeoutSeconds = 20,
    ) {}

    public function name(): string
    {
        return 'asaas';
    }

    public function createAccount(AccountOnboardingRequest $request): AccountSnapshot
    {
        $this->assertConfigured();

        $body = [
            'name' => $request->name,
            'email' => $request->email,
            'cpfCnpj' => $request->documentNumber,
            'mobilePhone' => $request->mobilePhone,
            'incomeValue' => (float) $request->monthlyIncome->toReais(),
            'address' => $request->address,
            'addressNumber' => $request->addressNumber,
            'province' => $request->province,
            'postalCode' => $request->postalCode,
        ];

        $response = $this->send('POST', '/v3/accounts', $this->apiKey, $body);

        if (! $response->successful()) {
            throw PaymentAccountProviderException::createFailed($this->name(), $this->errorDetail($response));
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        $id = is_string($data['id'] ?? null) ? $data['id'] : '';
        $accountApiKey = is_string($data['apiKey'] ?? null) ? $data['apiKey'] : '';
        $walletId = is_string($data['walletId'] ?? null) ? $data['walletId'] : '';

        if ($id === '' || $accountApiKey === '' || $walletId === '') {
            // Resposta 2xx sem os três campos que persistimos seria pior que
            // falhar alto: a subconta existiria no Asaas sem termos como
            // operá-la depois (CLAUDE.md §25).
            throw PaymentAccountProviderException::createFailed(
                $this->name(),
                'resposta sem id/apiKey/walletId',
            );
        }

        return new AccountSnapshot(externalAccountId: $id, apiKey: $accountApiKey, walletId: $walletId);
    }

    public function fetchAccountStatus(string $externalAccountId, string $accountApiKey): AccountStatusSnapshot
    {
        $this->assertConfigured();

        // Consultado com o apiKey DA SUBCONTA — é assim que o Asaas identifica
        // de qual conta se fala (docs/asaas.md §8).
        $response = $this->send('GET', '/v3/myAccount/status', $accountApiKey);

        if (! $response->successful()) {
            throw PaymentAccountProviderException::fetchFailed(
                $this->name(),
                $externalAccountId,
                $this->errorDetail($response),
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        $general = is_string($data['general'] ?? null) ? $data['general'] : 'UNKNOWN';

        return new AccountStatusSnapshot(approved: $general === 'APPROVED', rawStatus: $general);
    }

    /** @param  array<string, mixed>|null  $body */
    private function send(string $method, string $path, string $apiKey, ?array $body = null): Response
    {
        try {
            $request = $this->http
                ->baseUrl($this->baseUrl)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->withHeaders(['access_token' => $apiKey]);

            return $method === 'GET'
                ? $request->get($path)
                : $request->post($path, $body ?? []);
        } catch (ConnectionException $e) {
            throw PaymentAccountProviderException::createFailed($this->name(), 'connection: '.$e->getMessage());
        }
    }

    private function errorDetail(Response $response): string
    {
        /** @var array<string, mixed>|null $body */
        $body = $response->json();

        if (is_array($body) && is_array($body['errors'] ?? null)) {
            $parts = [];

            foreach ($body['errors'] as $error) {
                if (is_array($error)) {
                    $parts[] = trim(
                        (is_string($error['code'] ?? null) ? $error['code'] : '')
                        .' '
                        .(is_string($error['description'] ?? null) ? $error['description'] : '')
                    );
                }
            }

            if ($parts !== []) {
                return 'HTTP '.$response->status().': '.implode(' | ', $parts);
            }
        }

        return 'HTTP '.$response->status();
    }

    private function assertConfigured(): void
    {
        if ($this->apiKey === '') {
            throw PaymentAccountProviderException::misconfigured($this->name(), 'api_key');
        }

        if ($this->baseUrl === '') {
            throw PaymentAccountProviderException::misconfigured($this->name(), 'base_url');
        }
    }
}
