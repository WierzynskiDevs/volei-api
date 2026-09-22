<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos (BRIEF §17/§18/§19, ADR 0002, ADR 0006).
 *
 * Decisões materializadas aqui, todas já registradas em docs/:
 *
 *  - `start_at`/`end_at` são timestamptz em UTC — fonte de verdade do instante.
 *    A hora de parede do formulário é convertida uma única vez, na action, com
 *    `timezone` (ADR 0006). A coluna existe mesmo sendo constante na Fase 1:
 *    é ela que mantém aberto o caminho para eventos fora de UTC−3 sem migration.
 *
 *  - `registration_fee_cents` é bigint em centavos (CLAUDE.md §7). O baseline
 *    anunciava "R$ 120 / dupla" no card e cobrava R$ 130 no checkout
 *    (docs/DIVERGENCES.md §5); com um único campo a contradição some por
 *    construção — o texto passa a ser formatação.
 *
 *  - `max_teams` é NULLABLE: o formulário tem o checkbox "Sem máximo de equipes"
 *    (docs/DIVERGENCES.md §6). NULL = sem teto, e a trava de concorrência da
 *    última vaga só se aplica quando há teto.
 *
 *  - `status` aceita os 9 valores que a `EventStatusPill` já renderiza, mas a
 *    Fase 1 só transiciona os 6 do BRIEF §19. Os 3 de Fase 2 ficam reservados
 *    para não quebrar a tela (docs/DIVERGENCES.md §4).
 *
 *  - `courts`, `min_games` e `format` são dados inertes na Fase 1: o motor de
 *    competição é Fase 2 (docs/PHASE-2.md). São persistidos porque o formulário
 *    já os coleta, não porque exista algo consumindo-os.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Dono do evento. RESTRICT: apagar organizador com evento é erro,
            // não cascata silenciosa sobre dinheiro e inscrições.
            $table->foreignUuid('organizer_id')->constrained('organizers')->restrictOnDelete();

            $table->string('name', 160);
            $table->string('slug', 180);
            $table->text('description')->nullable();

            /*
             * Local. O baseline referencia a arena por NOME
             * (docs/DIVERGENCES.md §7) e o formulário permite local avulso.
             * A tabela `venues` com FK é do marketplace de arenas (Fase 2);
             * na Fase 1 o local é dado do próprio evento.
             */
            $table->string('venue_name', 160);
            $table->string('city', 120);
            $table->string('state', 2);

            // Instantes em UTC (CLAUDE.md §6). Hora local sempre derivada na leitura.
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->string('timezone', 64)->default('America/Sao_Paulo');

            // Janela de inscrição (ADR 0002). NULL = sem janela declarada.
            $table->timestampTz('registration_open_at')->nullable();
            $table->timestampTz('registration_close_at')->nullable();

            // Dinheiro: SEMPRE inteiro em centavos. 0 = evento gratuito.
            $table->bigInteger('registration_fee_cents')->default(0);

            $table->integer('max_teams')->nullable();   // NULL = sem teto

            // Dados inertes na Fase 1 (motor de competição é Fase 2).
            $table->smallInteger('courts')->default(1);
            $table->smallInteger('min_games')->default(1);
            $table->string('format', 120)->nullable();

            // Enums do BRIEF §18, com os valores extras que a UI já exibe
            // (docs/DIVERGENCES.md §3 e OPEN-QUESTIONS Q4).
            $table->string('modality', 32)->default('TWO_VS_TWO');
            $table->string('gender_category', 16)->default('OPEN');
            $table->string('level_category', 24)->default('FREE');
            $table->string('age_category', 16)->default('ADULT');
            $table->string('event_type', 24)->default('COMPETITIVE');

            $table->string('prize_description', 255)->nullable();
            $table->jsonb('rules')->default(DB::raw("'[]'::jsonb"));

            /*
             * Contador desnormalizado de duplas completas, exibido no card
             * ("16 / 16 duplas"). Quem o mantém é o módulo Registrations,
             * dentro da transação que confirma a inscrição — nunca o cliente,
             * nunca um COUNT() a cada listagem.
             */
            $table->integer('teams_registered_count')->default(0);

            $table->string('status', 32)->default('DRAFT');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            // Quem criou. Pode ser o próprio organizador ou um super admin.
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            /*
             * Índices (CLAUDE.md §6): toda FK e toda coluna de filtro/ordenação
             * das listas. A vitrine pública ordena por start_at dentro de um
             * conjunto de status — daí o índice composto.
             */
            $table->index('organizer_id');
            $table->index('status');
            $table->index('start_at');
            $table->index(['status', 'start_at']);
            $table->index(['state', 'city']);
            $table->index('registration_close_at');
        });

        /*
         * Slug único apenas entre os vivos: um evento apagado não pode bloquear
         * para sempre o endereço público /eventos/{slug}.
         */
        DB::statement('CREATE UNIQUE INDEX events_slug_unique ON events (slug) WHERE deleted_at IS NULL');

        // Invariantes que são do banco, não da aplicação (CLAUDE.md §6).
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_period_check CHECK (end_at > start_at)');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_registration_window_check CHECK (registration_open_at IS NULL OR registration_close_at IS NULL OR registration_close_at >= registration_open_at)');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_fee_check CHECK (registration_fee_cents >= 0)');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_max_teams_check CHECK (max_teams IS NULL OR max_teams > 0)');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_courts_check CHECK (courts >= 1)');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_min_games_check CHECK (min_games >= 1)');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_teams_count_check CHECK (teams_registered_count >= 0)');
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_state_check CHECK (state ~ '^[A-Z]{2}$')");

        DB::statement("ALTER TABLE events ADD CONSTRAINT events_status_check CHECK (status IN ('DRAFT','PUBLISHED','REGISTRATION_OPEN','REGISTRATION_CLOSED','AWAITING_DRAW','BRACKET_PUBLISHED','IN_PROGRESS','FINISHED','CANCELLED'))");
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_modality_check CHECK (modality IN ('TWO_VS_TWO','TWO_VS_TWO_ROTATING','FOUR_VS_FOUR'))");
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_gender_check CHECK (gender_category IN ('MALE','FEMALE','MIXED','OPEN'))");
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_level_check CHECK (level_category IN ('BEGINNER','INTERMEDIATE','ADVANCED','OPEN','FREE'))");
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_age_check CHECK (age_category IN ('ADULT','TEEN','CHILD'))");
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_type_check CHECK (event_type IN ('COMPETITIVE','SOCIAL','RANKING','FRIENDLY','LEAGUE','SPECIAL'))");

        /*
         * Um evento cancelado tem de ter justificativa registrada: o
         * cancelamento dispara obrigação de reembolso (BRIEF §35/§36), e
         * "por quê" não pode ser opcional na hora de devolver dinheiro.
         */
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_cancellation_check CHECK (status <> 'CANCELLED' OR (cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
