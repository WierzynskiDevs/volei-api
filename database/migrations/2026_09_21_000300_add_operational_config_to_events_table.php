<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configuração operacional do evento (ADR 0013 §2 — S8a).
 *
 * Dado inerte nesta fatia, mesma categoria de `courts`/`min_games`/`format`
 * (comentário original da migration de `events`): persistido porque o
 * formulário coleta, sem consumidor de cálculo ainda — o consumidor real
 * chega em S9 (`match_duration_min`/`best_of_sets`/`points_per_set` decidem
 * o encerramento de uma partida; `scoring_rules` continua inerte até o motor
 * de ranking existir, ADR 0008 §4).
 *
 * `scoring_rules` já tem shape fechado decidido pela ADR 0008:
 * `{win, loss, champion, runnerUp, third, participation}`, todos inteiros.
 * Default = a constante do ranking da plataforma (ADR 0008 §1) — é só o
 * valor inicial do formulário; o organizador pode configurar o dele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->smallInteger('days_count')->default(1);
            $table->smallInteger('match_duration_min')->default(35);
            $table->smallInteger('best_of_sets')->default(3);
            $table->smallInteger('points_per_set')->default(21);
            $table->smallInteger('tiebreak_points')->default(15);
            $table->jsonb('scoring_rules')->default(DB::raw(
                "'{\"win\":2,\"loss\":-1,\"champion\":10,\"runnerUp\":6,\"third\":3,\"participation\":1}'::jsonb"
            ));
        });

        DB::statement('ALTER TABLE events ADD CONSTRAINT events_days_count_check CHECK (days_count >= 1)');
        DB::statement(
            'ALTER TABLE events ADD CONSTRAINT events_match_duration_check CHECK (match_duration_min >= 1)'
        );
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_best_of_sets_check CHECK (best_of_sets IN (1,3,5))');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_points_per_set_check CHECK (points_per_set >= 1)');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_tiebreak_points_check CHECK (tiebreak_points >= 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_days_count_check');
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_match_duration_check');
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_best_of_sets_check');
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_points_per_set_check');
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_tiebreak_points_check');

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn([
                'days_count',
                'match_duration_min',
                'best_of_sets',
                'points_per_set',
                'tiebreak_points',
                'scoring_rules',
            ]);
        });
    }
};
