<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Events\Http\Policies\EventPolicy;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Operations\Domain\Services\RefereePhoneProtector;
use App\Modules\Organizers\Domain\Services\DocumentProtector;
use App\Modules\Payments\Domain\PaymentProviderInterface;
use App\Modules\Payments\Http\Policies\PaymentPolicy;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Payments\Infrastructure\Providers\AsaasPaymentProvider;
use App\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use App\Modules\Registrations\Http\Policies\RegistrationPolicy;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Users\Domain\Services\PhoneProtector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            PhoneProtector::class,
            fn (): PhoneProtector => new PhoneProtector(
                (string) config('saque.lgpd.phone_hash_pepper')
            )
        );

        $this->app->singleton(
            DocumentProtector::class,
            fn (): DocumentProtector => new DocumentProtector(
                (string) config('saque.lgpd.document_hash_pepper')
            )
        );

        $this->app->singleton(
            RefereePhoneProtector::class,
            fn (): RefereePhoneProtector => new RefereePhoneProtector(
                (string) config('saque.lgpd.referee_phone_hash_pepper')
            )
        );

        $this->registerPaymentProvider();
    }

    /**
     * Provedor de pagamento por configuração (ADR 0004).
     *
     * `fake` em desenvolvimento e em **todos** os testes — `CLAUDE.md` §22 exige
     * que teste financeiro não dependa de rede. `asaas` só quando explicitamente
     * configurado.
     *
     * O default é `fake` de propósito: uma instalação nova sem configurar nada
     * não deve tentar falar com gateway real. Errar para o lado de não cobrar é
     * recuperável; errar para o lado de cobrar no ambiente errado não é.
     */
    private function registerPaymentProvider(): void
    {
        $this->app->singleton(PaymentProviderInterface::class, function (): PaymentProviderInterface {
            $driver = (string) config('saque.payments.provider', 'fake');

            if ($driver !== 'asaas') {
                return new FakePaymentProvider;
            }

            return new AsaasPaymentProvider(
                http: $this->app->make(HttpFactory::class),
                baseUrl: (string) config('saque.payments.asaas.base_url'),
                apiKey: (string) config('saque.payments.asaas.api_key'),
                platformWalletId: (string) config('saque.payments.asaas.platform_wallet_id'),
                webhookToken: (string) config('saque.payments.asaas.webhook_token'),
                timeoutSeconds: (int) config('saque.payments.asaas.timeout_seconds', 20),
            );
        });
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureDates();
        $this->configurePolicies();
    }

    /**
     * A descoberta automática de policies do Laravel assume
     * `App\Models\X` → `App\Policies\XPolicy`. Com namespace modular ela não
     * encontra nada — e uma policy não encontrada falha **aberta**: o Gate
     * autoriza por ausência de regra. Por isso o registro é explícito.
     */
    private function configurePolicies(): void
    {
        Gate::policy(Event::class, EventPolicy::class);
        Gate::policy(Registration::class, RegistrationPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
    }

    /**
     * Guardas de modelo (CLAUDE.md §5).
     *
     * Ficam desligadas em produção para não derrubar requisição de usuário por
     * um lazy load esquecido — mas em desenvolvimento e teste falham alto, que
     * é onde o problema deve aparecer.
     */
    private function configureModels(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());

        // Mutação em massa sem $fillable explícito é erro, não conveniência.
        Model::unguard(false);
    }

    /**
     * CarbonImmutable evita a classe de bug em que uma data é mutada por
     * referência dentro de um cálculo — inaceitável em janela de inscrição
     * e vencimento de cobrança.
     */
    private function configureDates(): void
    {
        Date::use(CarbonImmutable::class);
    }
}
