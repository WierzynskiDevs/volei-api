<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sorteio/chaveamento inicial (ADR 0011).
 *
 * Escopo deliberadamente estreito: esta tabela guarda só a **posição inicial**
 * de cada dupla numa chave eliminatória simples de N posições (N = próxima
 * potência de 2 do nº de duplas `COMPLETE`). Não existe tabela de rodadas
 * seguintes, partida ou placar — isso é avanço de chave (S9, ainda Fase 2) e
 * fica para depois. Também não existe tabela `brackets` separada: `event_id`
 * já é a chave natural (1 evento → N posições), então uma tabela a mais só
 * seria um join sem função.
 *
 * `is_bye` e `registration_group_id` são mutuamente exclusivos: um slot ou
 * tem dupla, ou é vaga livre ("bye"), nunca os dois. Byes só existem depois de
 * publicada a chave (`PublishBracketAction` preenche o que sobrou vazio) —
 * antes disso um slot vazio é apenas "ainda não sorteado/encaixado".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bracket_slots', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // RESTRICT: apagar evento com chave sorteada é erro, não cascata.
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();

            $table->unsignedSmallInteger('position');

            /*
             * NULL = posição ainda sem dupla (rascunho de sorteio em
             * andamento). SET NULL: se a dupla for apagada, a posição some com
             * ela, mas o restante da chave continua existindo.
             */
            $table->foreignUuid('registration_group_id')->nullable()
                ->constrained('registration_groups')->nullOnDelete();

            $table->boolean('is_bye')->default(false);

            $table->timestampsTz();

            // Uma posição por evento, e é por aqui que a tela busca a chave.
            $table->unique(['event_id', 'position']);
            $table->index('event_id');
        });

        // Uma dupla não pode ocupar duas posições do mesmo evento.
        DB::statement(
            'CREATE UNIQUE INDEX bracket_slots_group_unique ON bracket_slots (event_id, registration_group_id) '.
            'WHERE registration_group_id IS NOT NULL',
        );

        // Bye e dupla são mutuamente exclusivos.
        DB::statement(
            'ALTER TABLE bracket_slots ADD CONSTRAINT bracket_slots_bye_xor_group '.
            'CHECK (NOT (is_bye AND registration_group_id IS NOT NULL))',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('bracket_slots');
    }
};
