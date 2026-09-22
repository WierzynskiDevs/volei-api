<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resultado oficial por set (ADR 0013 §3/§4/§9 — S9).
 *
 * Nunca ponto a ponto em tempo real (ATA §17, decisão vigente desde
 * `docs/SYNC-LOVABLE-2026-08.md` §17) — só o placar final de cada set.
 * `set_number`, não `index`: mesmo dado, nome que não arrisca colidir com
 * palavra reservada em nenhum SGBD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_sets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('match_id')->constrained('matches')->cascadeOnDelete();
            $table->smallInteger('set_number');
            $table->smallInteger('score_a');
            $table->smallInteger('score_b');
            $table->timestampsTz();

            $table->unique(['match_id', 'set_number']);
        });

        DB::statement('ALTER TABLE match_sets ADD CONSTRAINT match_sets_set_number_check CHECK (set_number >= 1)');
        DB::statement('ALTER TABLE match_sets ADD CONSTRAINT match_sets_score_a_check CHECK (score_a >= 0)');
        DB::statement('ALTER TABLE match_sets ADD CONSTRAINT match_sets_score_b_check CHECK (score_b >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('match_sets');
    }
};
