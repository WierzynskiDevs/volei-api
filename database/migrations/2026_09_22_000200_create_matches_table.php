<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partida (ADR 0013 §2/§3/§4/§9 — S9, o núcleo do operacional).
 *
 * `team_a_id`/`team_b_id` apontam para `registration_groups` (a dupla,
 * ADR 0001) — nunca criadas a partir de `bracket_slots`: avanço automático
 * de chave continua Fase 2 (ADR 0011 §Consequências, ADR 0013 §1). O
 * organizador monta o confronto manualmente, inclusive para evento sem
 * sorteio formal.
 *
 * Nomes de status em português — espelham `OpsStatus` do baseline
 * (`operations.tsx`), mesma convenção dos `*_LABEL` do frontend.
 *
 * Lock é sempre pessimista (`SELECT ... FOR UPDATE`), mesmo padrão do resto
 * do sistema (`EventOccupancy`) — sem coluna `version` de lock otimista que
 * a ADR 0013 §9 cogitava: duplicar os dois mecanismos seria inconsistência,
 * não robustez extra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('court_id')->nullable()->constrained('courts')->nullOnDelete();
            $table->foreignUuid('referee_id')->nullable()->constrained('event_referees')->nullOnDelete();
            $table->foreignUuid('team_a_id')->constrained('registration_groups')->restrictOnDelete();
            $table->foreignUuid('team_b_id')->constrained('registration_groups')->restrictOnDelete();

            // Rótulo livre do organizador ("Grupo A", "Semifinal") — dado
            // inerte, sem avanço de chave associado nesta fatia.
            $table->string('phase', 60)->nullable();

            $table->string('status', 16)->default('PENDENTE');

            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->foreignUuid('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('finished_by')->nullable()->constrained('users')->nullOnDelete();

            // Guardam a `Idempotency-Key` que efetivou a transição (CLAUDE.md §8).
            // Replay com a MESMA chave devolve a partida como está, sem
            // reexecutar; chave diferente contra um estado já avançado é
            // conflito real (409) — nunca coluna de dedupe global como em
            // `payments`, porque aqui a chave só precisa ser única por partida.
            $table->string('start_idempotency_key', 128)->nullable();
            $table->string('finish_idempotency_key', 128)->nullable();

            $table->timestampsTz();

            $table->index(['event_id', 'status']);
            $table->index(['event_id', 'court_id']);
            $table->index(['event_id', 'referee_id']);
        });

        DB::statement(
            'ALTER TABLE matches ADD CONSTRAINT matches_status_check '
            ."CHECK (status IN ('PENDENTE','ATRIBUIDA','PRONTA','EM_ANDAMENTO','FINALIZADA','CANCELADA','ADIADA'))"
        );
        DB::statement('ALTER TABLE matches ADD CONSTRAINT matches_teams_distinct_check CHECK (team_a_id <> team_b_id)');
        DB::statement(
            'ALTER TABLE matches ADD CONSTRAINT matches_started_check '
            ."CHECK (status NOT IN ('EM_ANDAMENTO','FINALIZADA') OR started_at IS NOT NULL)"
        );
        DB::statement(
            'ALTER TABLE matches ADD CONSTRAINT matches_finished_check '
            ."CHECK (status <> 'FINALIZADA' OR finished_at IS NOT NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
