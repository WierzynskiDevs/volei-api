<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inscrições e duplas (BRIEF §21, ADR 0001, ADR 0003, aditivo §17/§18/§19).
 *
 * Decisões materializadas aqui:
 *
 *  - A inscrição é **por jogador** (ADR 0001): `registrations(event_id, user_id)`.
 *    A dupla é `registration_groups`, e `registrations.group_id` é NULLABLE
 *    porque os modos "encontrar parceiro" e "individual" não nascem com dupla.
 *
 *  - A vaga é ocupada na confirmação do pagamento, com reserva temporária
 *    durante o checkout (ADR 0003). Daí `reserved_until`.
 *
 *  - `level_review` é coluna **separada** de `status`, e não um valor dentro
 *    dele. O aditivo §17 determina que nível incompatível não bloqueia a
 *    inscrição, e a tela `/inscricao/{slug}` já promete isso ao atleta. Com um
 *    enum único seria preciso escolher entre travar a inscrição e perder a
 *    informação da análise.
 *
 *  - `player_level` é **snapshot** do nível no momento da inscrição. O aditivo
 *    §16 permite ao atleta editar o nível; sem o snapshot, uma edição posterior
 *    reescreveria a base da decisão do organizador — o mesmo raciocínio da taxa
 *    congelada (CLAUDE.md §7.5).
 *
 *  - Sem soft delete. CLAUDE.md §6 restringe soft delete a users/events/
 *    organizers, e ADR 0003 é explícito: reserva expirada NÃO apaga a inscrição,
 *    vai para estado terminal auditável.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // RESTRICT: apagar evento com dupla inscrita é erro, não cascata.
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();

            /*
             * Nome de exibição da dupla ("Mendes / Alves"). O organizador pode
             * editar na aba Duplas da página do evento — o baseline já tem o
             * lápis e o input. NULL = derivar dos nomes dos membros na leitura.
             */
            $table->string('display_name', 160)->nullable();

            $table->string('status', 24)->default('FORMING');

            $table->timestampsTz();

            $table->index('event_id');
            $table->index(['event_id', 'status']);
        });

        DB::statement("ALTER TABLE registration_groups ADD CONSTRAINT registration_groups_status_check CHECK (status IN ('FORMING','COMPLETE','INCOMPLETE','CANCELLED'))");

        Schema::create('registrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();

            /*
             * SET NULL: se a dupla for apagada, a inscrição do atleta continua
             * existindo — ela é a unidade de cobrança (ADR 0001) e não pode
             * desaparecer junto com um agrupamento.
             */
            $table->foreignUuid('group_id')->nullable()->constrained('registration_groups')->nullOnDelete();

            /*
             * Quem criou a dupla. É o capitão que o aditivo §18 manda notificar
             * quando a inscrição é reprovada.
             */
            $table->boolean('is_captain')->default(false);

            $table->string('partner_mode', 16);
            $table->string('status', 32)->default('PENDING_PAYMENT');

            // --- Análise de nível (aditivo §17/§18) ---
            $table->string('level_review', 16)->default('NOT_REQUIRED');
            $table->string('player_level', 24)->nullable();   // snapshot
            $table->string('event_level', 24);                // snapshot da categoria
            $table->foreignUuid('level_review_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('level_review_decided_at')->nullable();
            $table->text('level_review_reason')->nullable();

            // --- Reserva e ciclo de vida (ADR 0003) ---
            $table->timestampTz('reserved_until')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            /*
             * Versão do regulamento aceita no ato da inscrição. A tela já afirma
             * "ao se inscrever você aceita o regulamento v1.0"; CLAUDE.md §12
             * exige que consentimento fique gravado com versão.
             */
            $table->string('accepted_rules_version', 16)->nullable();

            $table->timestampsTz();

            $table->index('event_id');
            $table->index('user_id');
            $table->index('group_id');
            $table->index(['event_id', 'status']);
            // Job de expiração de reserva varre por aqui.
            $table->index('reserved_until');
        });

        /*
         * Duplicidade: um atleta tem no máximo UMA inscrição ativa por evento.
         *
         * Unique **parcial**: estado terminal não conta, porque quem teve a
         * reserva expirada ou cancelou precisa poder se inscrever de novo
         * enquanto houver vaga. Esta é a última linha de defesa contra duplo
         * clique, atrás do lock da action (CLAUDE.md §8).
         */
        DB::statement("CREATE UNIQUE INDEX registrations_active_unique ON registrations (event_id, user_id) WHERE status NOT IN ('CANCELLED','EXPIRED')");

        // Fila "Inscrição aguardando análise" do painel do organizador.
        DB::statement("CREATE INDEX registrations_pending_review ON registrations (event_id) WHERE level_review = 'REQUIRED'");

        DB::statement("ALTER TABLE registrations ADD CONSTRAINT registrations_status_check CHECK (status IN ('PENDING_ACCEPTANCE','PENDING_PAYMENT','CONFIRMED','CANCELLED','EXPIRED'))");
        DB::statement("ALTER TABLE registrations ADD CONSTRAINT registrations_level_review_check CHECK (level_review IN ('NOT_REQUIRED','REQUIRED','APPROVED','REJECTED'))");
        DB::statement("ALTER TABLE registrations ADD CONSTRAINT registrations_partner_mode_check CHECK (partner_mode IN ('PARTNER','SEEKING','INDIVIDUAL'))");

        /*
         * Coerência entre estado e timestamp. Sem isto, um bug de aplicação
         * poderia gravar CONFIRMED sem `confirmed_at` — e o financeiro passaria
         * a ter inscrição confirmada sem instante de confirmação.
         */
        DB::statement("ALTER TABLE registrations ADD CONSTRAINT registrations_confirmed_check CHECK (status <> 'CONFIRMED' OR confirmed_at IS NOT NULL)");
        DB::statement("ALTER TABLE registrations ADD CONSTRAINT registrations_cancelled_check CHECK (status <> 'CANCELLED' OR cancelled_at IS NOT NULL)");

        /*
         * Decisão de nível exige autor e data. O aditivo §17 manda registrar
         * "decisão, usuário, data" — deixar isso só na aplicação permitiria
         * decisão órfã.
         */
        DB::statement("ALTER TABLE registrations ADD CONSTRAINT registrations_level_decision_check CHECK (level_review NOT IN ('APPROVED','REJECTED') OR (level_review_decided_by IS NOT NULL AND level_review_decided_at IS NOT NULL))");

        // Modo "parceiro" nasce com dupla; sem grupo não existe dupla.
        DB::statement("ALTER TABLE registrations ADD CONSTRAINT registrations_partner_group_check CHECK (partner_mode <> 'PARTNER' OR group_id IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
        Schema::dropIfExists('registration_groups');
    }
};
