<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Rate limiting (CLAUDE.md §11).
 *
 * Limitar só por IP não protege: um atacante com muitos IPs continua fazendo
 * força bruta numa conta específica. Por isso os limites de autenticação são
 * por IP **e** por identidade alvo.
 *
 * A resposta 429 é sempre igual, exista a conta ou não — o limite não pode
 * virar oráculo de enumeração.
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('auth-login', fn (Request $request): array => [
            Limit::perMinute(5)->by('login-id:'.mb_strtolower((string) $request->input('email'))),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('auth-register', fn (Request $request): Limit => Limit::perHour(10)->by('register-ip:'.$request->ip())
        );

        RateLimiter::for('auth-password', fn (Request $request): array => [
            Limit::perMinute(3)->by('pwd-id:'.mb_strtolower((string) $request->input('email'))),
            Limit::perHour(20)->by('pwd-ip:'.$request->ip()),
        ]);

        /*
         * Criação de inscrição. Limite por identidade, não por IP: numa arena
         * com wifi compartilhado várias pessoas se inscrevem do mesmo IP ao
         * mesmo tempo, e um limite por IP recusaria inscrição legítima na hora
         * em que o evento mais recebe gente.
         */
        RateLimiter::for('registrations', fn (Request $request): Limit => Limit::perMinute(10)->by('reg-user:'.self::identify($request))
        );

        // Criação de cobrança: protege contra duplo clique e contra abuso de
        // criação de cobranças no gateway (CLAUDE.md §8 trata a idempotência).
        RateLimiter::for('payments', fn (Request $request): Limit => Limit::perMinute(10)->by('pay-user:'.self::identify($request))
        );

        // Webhook: limite alto — o gateway pode legitimamente reenviar em lote.
        // Baixo demais aqui significa perder confirmação de pagamento.
        RateLimiter::for('webhooks', fn (Request $request): Limit => Limit::perMinute(300)->by('webhook-ip:'.$request->ip())
        );

        /*
         * Ações administrativas destrutivas: bloquear conta, mudar situação de
         * organizador, cancelar evento.
         *
         * O limite não existe para conter o super admin — existe para conter
         * quem estiver usando a sessão dele. Suspensão em rajada é sinal de
         * conta comprometida, não de expediente: 30 por minuto é folgado para
         * trabalho humano e estreito para script.
         */
        RateLimiter::for('admin-actions', fn (Request $request): Limit => Limit::perMinute(30)->by('admin:'.self::identify($request))
        );

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by(self::identify($request))
        );
    }

    /**
     * Identidade para a chave de limite: usuário autenticado quando houver,
     * IP caso contrário.
     */
    private static function identify(Request $request): string
    {
        $user = $request->user();

        return $user !== null
            ? (string) $user->getAuthIdentifier()
            : (string) $request->ip();
    }
}
