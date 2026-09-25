<?php

declare(strict_types=1);

use App\Modules\Administration\Http\Controllers\AdminAuditLogController;
use App\Modules\Administration\Http\Controllers\AdminDashboardController;
use App\Modules\Administration\Http\Controllers\AdminEventController;
use App\Modules\Administration\Http\Controllers\AdminFinanceController;
use App\Modules\Administration\Http\Controllers\AdminOrganizerController;
use App\Modules\Administration\Http\Controllers\AdminPaymentController;
use App\Modules\Administration\Http\Controllers\AdminUserController;
use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Brackets\Http\Controllers\OrganizerBracketController;
use App\Modules\Brackets\Http\Controllers\PublicBracketController;
use App\Modules\Events\Http\Controllers\OrganizerEventController;
use App\Modules\Events\Http\Controllers\PublicEventController;
use App\Modules\Finance\Http\Controllers\OrganizerFinanceController;
use App\Modules\Operations\Http\Controllers\MatchController;
use App\Modules\Operations\Http\Controllers\OrganizerCourtController;
use App\Modules\Operations\Http\Controllers\OrganizerRefereeController;
use App\Modules\Operations\Http\Controllers\PublicMatchController;
use App\Modules\Operations\Http\Controllers\RefereeInvitationController;
use App\Modules\Operations\Http\Controllers\RefereeMatchController;
use App\Modules\Organizers\Http\Controllers\OrganizerOnboardingController;
use App\Modules\Organizers\Http\Controllers\OrganizerPaymentAccountController;
use App\Modules\Organizers\Http\Controllers\OrganizerPlanController;
use App\Modules\Payments\Http\Controllers\PaymentController;
use App\Modules\Payments\Http\Controllers\WebhookController;
use App\Modules\Registrations\Http\Controllers\OrganizerRegistrationController;
use App\Modules\Registrations\Http\Controllers\RegistrationController;
use App\Modules\Users\Http\Controllers\PlayerSearchController;
use Illuminate\Support\Facades\Route;

/*
 * API v1 (CLAUDE.md §13).
 *
 * Regra do BRIEF §48: um endpoint só existe quando há consumidor real e
 * identificado no volei-app. Nada é criado "por completude".
 *
 * Rate limiting (CLAUDE.md §11): os limites por IP+identidade estão definidos
 * em App\Providers\RateLimitServiceProvider.
 */

Route::prefix('v1')->group(function (): void {

    /* ---------------------------------------------------------------- *
     * Autenticação — público
     * ---------------------------------------------------------------- */
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:auth-register')
        ->name('auth.register');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login')
        ->name('auth.login');

    /* ---------------------------------------------------------------- *
     * Eventos — vitrine pública
     *
     * Sem autenticação: a página do evento é pública ("não é necessário ter
     * conta para visualizar", texto do próprio baseline). Só devolve dado não
     * sensível — nada de e-mail ou telefone de organizador (CLAUDE.md §12).
     *
     * Consumidores: /eventos e /eventos/{slug} no volei-app.
     * ---------------------------------------------------------------- */
    Route::get('events', [PublicEventController::class, 'index'])->name('events.index');
    Route::get('events/{slug}', [PublicEventController::class, 'show'])->name('events.show');

    /* ---------------------------------------------------------------- *
     * Chaveamento e partidas — público (ADR 0017, Q15)
     *
     * Sem autenticação: quem abre o link do evento vê a chave e os
     * resultados. Nunca visível antes de `BRACKET_PUBLISHED` — em rascunho
     * é ferramenta de trabalho do organizador, não dado público (ADR 0011
     * §7). `throttle:api` porque, mesmo sem sessão, é leitura de terceiro
     * (mesmo padrão de `referee-invitations`/`players`).
     *
     * Consumidor: abas "Chaveamento"/"Agenda"/"Resultados" de
     * eventos.$slug.tsx no volei-app.
     * ---------------------------------------------------------------- */
    Route::get('events/{slug}/bracket', [PublicBracketController::class, 'show'])
        ->middleware('throttle:api')
        ->name('events.bracket.show');
    Route::get('events/{slug}/matches', [PublicMatchController::class, 'index'])
        ->middleware('throttle:api')
        ->name('events.matches.index');

    /* ---------------------------------------------------------------- *
     * Webhook do gateway — público por natureza (CLAUDE.md §14)
     *
     * O gateway não tem sessão. A autenticação é o token compartilhado no
     * header `asaas-access-token`, verificado pelo provider.
     *
     * Limite alto de propósito: o Asaas pode reenviar em lote, e um limite
     * baixo aqui significaria perder confirmação de pagamento.
     * ---------------------------------------------------------------- */
    Route::post('webhooks/asaas', [WebhookController::class, 'asaas'])
        ->middleware('throttle:webhooks')
        ->name('webhooks.asaas');

    /* ---------------------------------------------------------------- *
     * Convite de juiz — rota pública, sem sessão (ADR 0013 §5, S8b)
     *
     * O token no link é a credencial. `throttle:api` porque, mesmo o token
     * sendo praticamente impossível de adivinhar (48 chars aleatórios),
     * nenhuma rota pública fica sem limite de taxa (CLAUDE.md §11).
     * ---------------------------------------------------------------- */
    Route::get('referee-invitations/{token}', [RefereeInvitationController::class, 'show'])
        ->middleware('throttle:api')
        ->name('referee-invitations.show');
    Route::post('referee-invitations/{token}/accept', [RefereeInvitationController::class, 'accept'])
        ->middleware('throttle:api')
        ->name('referee-invitations.accept');

    /* ---------------------------------------------------------------- *
     * Área do juiz — sessão por token, nunca por cookie (ADR 0013 §5/§7)
     *
     * `referee-session` resolve o Bearer token contra `personal_access_tokens`
     * e recusa qualquer coisa que não seja um `EventReferee` — nunca
     * `auth:sanctum` (aquele guard é exclusivo do cookie SPA de `User`).
     *
     * Consumidor: /juiz no volei-app.
     * ---------------------------------------------------------------- */
    Route::middleware(['referee-session', 'throttle:api'])->group(function (): void {
        Route::get('referee/matches', [RefereeMatchController::class, 'index'])
            ->name('referee.matches.index');
        Route::post('referee/matches/{match}/start', [RefereeMatchController::class, 'start'])
            ->name('referee.matches.start');
        Route::put('referee/matches/{match}/sets', [RefereeMatchController::class, 'recordSet'])
            ->name('referee.matches.sets');
        Route::post('referee/matches/{match}/finish', [RefereeMatchController::class, 'finish'])
            ->name('referee.matches.finish');
    });

    /* ---------------------------------------------------------------- *
     * Autenticação — sessão ativa
     * ---------------------------------------------------------------- */
    /*
     * `active-account` acompanha `auth:sanctum` em TODA rota autenticada.
     *
     * Sem ele, `UserStatus::BLOCKED` só era verificado no login: a sessão aberta
     * antes da suspensão continuava operando até a pessoa deslogar por conta
     * própria (ADR 0010 §3). Com o painel administrativo entregando o botão de
     * suspender, isso deixou de ser hipótese.
     */
    Route::middleware(['auth:sanctum', 'active-account'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        /* ------------------------------------------------------------ *
         * Onboarding do organizador (OPEN-QUESTIONS Q6, ADR 0014).
         *
         * Fora do prefixo `organizer.` de propósito: é a ação de SE TORNAR
         * organizador, não uma ação de quem já é. Consumidor: `/onboarding`.
         * ------------------------------------------------------------ */
        Route::post('organizers', [OrganizerOnboardingController::class, 'store'])
            ->middleware('throttle:api')
            ->name('organizers.store');

        /* ------------------------------------------------------------ *
         * Eventos — painel do organizador
         *
         * Ownership é verificado pela EventPolicy por comparação de FK, não
         * por parâmetro de URL (CLAUDE.md §10). O binding é por slug porque é
         * o identificador que o volei-app já usa nas rotas de tela.
         *
         * Consumidores: /organizador/eventos, /organizador/novo-evento e
         * /organizador/alterar-evento/{slug}.
         * ------------------------------------------------------------ */
        Route::prefix('organizer')->name('organizer.')->group(function (): void {
            Route::get('events', [OrganizerEventController::class, 'index'])->name('events.index');
            Route::post('events', [OrganizerEventController::class, 'store'])->name('events.store');
            Route::get('events/{event:slug}', [OrganizerEventController::class, 'show'])->name('events.show');
            Route::patch('events/{event:slug}', [OrganizerEventController::class, 'update'])->name('events.update');

            // Transições de estado são sub-recurso explícito, não CRUD
            // disfarçado de PATCH em `status` (CLAUDE.md §13).
            Route::post('events/{event:slug}/publish', [OrganizerEventController::class, 'publish'])
                ->name('events.publish');
            Route::post('events/{event:slug}/open-registrations', [OrganizerEventController::class, 'openRegistrations'])
                ->name('events.open-registrations');
            Route::post('events/{event:slug}/close-registrations', [OrganizerEventController::class, 'closeRegistrations'])
                ->name('events.close-registrations');
            Route::post('events/{event:slug}/cancel', [OrganizerEventController::class, 'cancel'])
                ->name('events.cancel');

            /* -------------------------------------------------------- *
             * Sorteio/chaveamento inicial (ADR 0011) — consumidor:
             * /organizador/sorteio/{slug}.
             *
             * `changeStatus` é reaproveitada de propósito: é a mesma regra de
             * "organizador dono do evento, ou super admin" usada por
             * publish/cancel — o sorteio também muda o estado do evento
             * (REGISTRATION_CLOSED → AWAITING_DRAW → BRACKET_PUBLISHED).
             * -------------------------------------------------------- */
            Route::get('events/{event:slug}/bracket', [OrganizerBracketController::class, 'show'])
                ->name('events.bracket.show');
            Route::post('events/{event:slug}/bracket/start-draw', [OrganizerBracketController::class, 'start'])
                ->name('events.bracket.start-draw');
            Route::post('events/{event:slug}/bracket/randomize', [OrganizerBracketController::class, 'randomize'])
                ->name('events.bracket.randomize');
            Route::patch('events/{event:slug}/bracket/slots/{position}', [OrganizerBracketController::class, 'placeGroup'])
                ->whereNumber('position')
                ->name('events.bracket.slots.update');
            Route::post('events/{event:slug}/bracket/publish', [OrganizerBracketController::class, 'publish'])
                ->name('events.bracket.publish');

            /* -------------------------------------------------------- *
             * Quadras (ADR 0013 §2/§8 — S8a). Autorização é a mesma de
             * editar o evento — configurar quadra é configuração de evento,
             * não ação de status.
             * -------------------------------------------------------- */
            Route::get('events/{event:slug}/courts', [OrganizerCourtController::class, 'index'])
                ->name('events.courts.index');
            Route::put('events/{event:slug}/courts', [OrganizerCourtController::class, 'replace'])
                ->name('events.courts.replace');

            /* -------------------------------------------------------- *
             * Juízes (ADR 0013 §5/§6/§8 — S8b).
             * -------------------------------------------------------- */
            Route::get('events/{event:slug}/referees', [OrganizerRefereeController::class, 'index'])
                ->name('events.referees.index');
            Route::post('events/{event:slug}/referees', [OrganizerRefereeController::class, 'store'])
                ->name('events.referees.store');
            Route::post('events/{event:slug}/referees/{referee}/invite', [OrganizerRefereeController::class, 'invite'])
                ->name('events.referees.invite');
            Route::patch('events/{event:slug}/referees/{referee}', [OrganizerRefereeController::class, 'update'])
                ->name('events.referees.update');

            /* -------------------------------------------------------- *
             * Partidas e sets (ADR 0013 §4/§7/§8 — S9). Nesta fatia, todo
             * o ciclo é operado pelo organizador (mesma EventPolicy::update
             * de quadras/juízes) — acesso direto do juiz por sessão
             * escopada por token fica para a fatia seguinte.
             * -------------------------------------------------------- */
            Route::get('events/{event:slug}/matches', [MatchController::class, 'index'])
                ->name('events.matches.index');
            Route::post('events/{event:slug}/matches', [MatchController::class, 'store'])
                ->name('events.matches.store');
            Route::post('events/{event:slug}/matches/{match}/court', [MatchController::class, 'assignCourt'])
                ->name('events.matches.court');
            Route::post('events/{event:slug}/matches/{match}/referee', [MatchController::class, 'assignReferee'])
                ->name('events.matches.referee');
            Route::post('events/{event:slug}/matches/{match}/start', [MatchController::class, 'start'])
                ->name('events.matches.start');
            Route::put('events/{event:slug}/matches/{match}/sets', [MatchController::class, 'recordSet'])
                ->name('events.matches.sets');
            Route::post('events/{event:slug}/matches/{match}/finish', [MatchController::class, 'finish'])
                ->name('events.matches.finish');
            Route::post('events/{event:slug}/matches/{match}/cancel', [MatchController::class, 'cancel'])
                ->name('events.matches.cancel');

            /* -------------------------------------------------------- *
             * Inscrições do evento — lista e análise de nível
             *
             * A lista pende do evento de propósito: a EventPolicy já prova
             * ownership antes de qualquer inscrição ser lida, e não existe
             * rota que aceite `organizer_id` do cliente (aditivo §26).
             *
             * Consumidores: fila "Inscrição aguardando análise" em
             * /organizador e a lista de inscritos do evento.
             * -------------------------------------------------------- */
            Route::get('events/{event:slug}/registrations', [OrganizerRegistrationController::class, 'index'])
                ->name('events.registrations.index');

            // Fila multi-evento — consumidor: painel "Inscrição aguardando
            // análise" em /organizador, que não escolhe evento.
            Route::get('registrations', [OrganizerRegistrationController::class, 'queue'])
                ->name('registrations.queue');

            /*
             * Financeiro do próprio organizador — consumidor:
             * /organizador/financeiro.
             *
             * Sem parâmetro `organizer_id`: o escopo vem da sessão. É a mesma
             * proteção da lista de inscrições — não existe URL que aceite o id
             * de outro organizador (§10).
             */
            Route::get('finance', OrganizerFinanceController::class)->name('finance');

            // Plano do organizador — consumidor: /organizador/plano.
            Route::get('plan', OrganizerPlanController::class)->name('plan');

            /*
             * Vinculação da conta de recebimento (ADR 0018) — consumidor:
             * /organizador/plano, seção "Conta Asaas".
             */
            Route::post('payment-account', [OrganizerPaymentAccountController::class, 'store'])
                ->name('payment-account.store');

            Route::post('registrations/{registration}/approve', [OrganizerRegistrationController::class, 'approve'])
                ->name('registrations.approve');
            Route::post('registrations/{registration}/reject', [OrganizerRegistrationController::class, 'reject'])
                ->name('registrations.reject');
        });

        /* ------------------------------------------------------------ *
         * Inscrições — lado do atleta
         *
         * Consumidores: /inscricao/{slug} (criar) e /minhas-inscricoes
         * (listar, cancelar).
         *
         * A vaga é ocupada na confirmação do pagamento, com reserva
         * temporária durante o checkout (ADR 0003) — a trava de última vaga
         * está na action, dentro da transação.
         * ------------------------------------------------------------ */
        /*
         * Busca de atletas para formar dupla. Autenticada e com termo mínimo:
         * é uma busca, não um diretório (CLAUDE.md §12).
         *
         * Consumidores: seletor "Escolha o parceiro" em /inscricao/{slug} e a
         * busca de /trocar-dupla.
         */
        Route::get('players', [PlayerSearchController::class, 'index'])
            ->middleware('throttle:api')
            ->name('players.index');

        Route::get('me/registrations', [RegistrationController::class, 'index'])
            ->name('me.registrations.index');

        Route::post('events/{event:slug}/registrations', [RegistrationController::class, 'store'])
            ->middleware('throttle:registrations')
            ->name('events.registrations.store');

        Route::delete('registrations/{registration}', [RegistrationController::class, 'destroy'])
            ->name('registrations.destroy');

        // Q14/ADR 0015 — o parceiro convidado aceita entrar na dupla.
        Route::post('registrations/{registration}/accept', [RegistrationController::class, 'accept'])
            ->name('registrations.accept');

        /* ------------------------------------------------------------ *
         * Pagamentos — consumidor: /checkout/{slug}
         *
         * `Idempotency-Key` é OBRIGATÓRIO (CLAUDE.md §8): chave repetida
         * devolve a mesma resposta sem criar nova cobrança no gateway. É o
         * que impede que duplo clique no checkout cobre duas vezes.
         *
         * A confirmação NUNCA vem daqui: o checkout consulta
         * `GET /payments/{id}`, e quem move o estado é o webhook verificado
         * ou a reconciliação (§14).
         * ------------------------------------------------------------ */
        Route::post('registrations/{registration}/payments', [PaymentController::class, 'store'])
            ->middleware('throttle:payments')
            ->name('registrations.payments.store');

        Route::get('payments/{payment}', [PaymentController::class, 'show'])
            ->name('payments.show');

        /* ------------------------------------------------------------ *
         * Administração — painel do super admin (ADR 0010)
         *
         * Autorização em duas camadas (§4 do ADR): o middleware `super-admin`
         * fecha o grupo inteiro, e as policies continuam valendo dentro dos
         * endpoints que as usam. Middleware sozinho seria ponto único de falha;
         * policy sozinha dependeria de o controller lembrar de chamá-la.
         *
         * Escopo: as seis superfícies do CLAUDE.md §13 — users, organizers,
         * events, payments, finance, audit-logs. Reembolso, planos, denúncias,
         * feedbacks, arenas e publicidade ficam fora, com motivo registrado no
         * ADR 0010 §1.
         *
         * Não existe endpoint administrativo que mude status de pagamento: quem
         * move o dinheiro é webhook verificado ou reconciliação (§14).
         * ------------------------------------------------------------ */
        Route::prefix('admin')->name('admin.')->middleware('super-admin')->group(function (): void {
            Route::get('overview', AdminDashboardController::class)->name('overview');

            Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
            Route::get('organizers', [AdminOrganizerController::class, 'index'])->name('organizers.index');
            Route::get('organizers/{organizer}', [AdminOrganizerController::class, 'show'])->name('organizers.show');
            Route::get('events', [AdminEventController::class, 'index'])->name('events.index');
            Route::get('payments', [AdminPaymentController::class, 'index'])->name('payments.index');
            Route::get('payments/{payment}', [AdminPaymentController::class, 'show'])->name('payments.show');
            Route::get('finance', [AdminFinanceController::class, 'summary'])->name('finance.summary');
            Route::get('audit-logs', [AdminAuditLogController::class, 'index'])->name('audit-logs.index');

            /*
             * Ações de estado: sub-recurso explícito, nunca PATCH num campo
             * `status` (§13). Todas exigem justificativa, que vai para a trilha
             * (ADR 0010 §5), e todas passam por rate limit — ação
             * administrativa em rajada é sinal de conta comprometida, não de
             * uso normal.
             */
            Route::middleware('throttle:admin-actions')->group(function (): void {
                Route::post('users/{user}/block', [AdminUserController::class, 'block'])
                    ->name('users.block');
                Route::post('users/{user}/unblock', [AdminUserController::class, 'unblock'])
                    ->name('users.unblock');

                Route::post('organizers/{organizer}/status', [AdminOrganizerController::class, 'updateStatus'])
                    ->name('organizers.status');

                Route::post('events/{event:slug}/cancel', [AdminEventController::class, 'cancel'])
                    ->name('events.cancel');
            });
        });
    });

});
