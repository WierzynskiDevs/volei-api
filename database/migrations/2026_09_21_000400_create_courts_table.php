<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quadras do evento (ADR 0013 §2/§8 — módulo Operations, S8a).
 *
 * Não confundir com `events.courts` (contador simples, já existia, usado na
 * heurística de viabilidade do frontend) — esta tabela é a quadra NOMEADA
 * ("Quadra Central", "Quadra 2") que o kanban do S9 vai atribuir a partidas.
 *
 * Cascade on delete: quadra é configuração pura do evento, sem significado
 * fora dele — some com o evento como `rules` some (nenhuma auditoria/dinheiro
 * depende de quadra sobreviver ao evento).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('label', 60);
            $table->smallInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['event_id', 'label']);
            $table->index(['event_id', 'position']);
        });

        DB::statement('ALTER TABLE courts ADD CONSTRAINT courts_position_check CHECK (position >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('courts');
    }
};
