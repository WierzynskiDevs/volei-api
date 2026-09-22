<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CPF/CNPJ do organizador — exceção deliberada ao CLAUDE.md §12, registrada em
 * docs/adr/0014-cpf-cnpj-do-organizador.md. Exigido pelo Asaas para abrir a
 * subconta (docs/asaas.md §8): sem ele não há como o organizador receber.
 *
 * Nullable: organizadores existentes (seed/demo) não têm o dado, e não há
 * backfill — a exigência vale para o fluxo de criação a partir de agora
 * (CreateOrganizerAction), não retroativamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizers', function (Blueprint $table): void {
            $table->text('document_number_encrypted')->nullable()->after('contact_phone_encrypted');
            $table->string('document_number_hash', 64)->nullable()->after('document_number_encrypted');
            $table->string('document_type', 8)->nullable()->after('document_number_hash');
        });

        DB::statement(
            'ALTER TABLE organizers ADD CONSTRAINT organizers_document_type_check '
            ."CHECK (document_type IS NULL OR document_type IN ('CPF','CNPJ'))"
        );

        // Índice único parcial: dois organizadores nunca compartilham documento,
        // mas linhas antigas sem o dado (NULL) não colidem entre si.
        DB::statement(
            'CREATE UNIQUE INDEX organizers_document_number_hash_unique '
            .'ON organizers (document_number_hash) WHERE document_number_hash IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS organizers_document_number_hash_unique');
        DB::statement('ALTER TABLE organizers DROP CONSTRAINT IF EXISTS organizers_document_type_check');

        Schema::table('organizers', function (Blueprint $table): void {
            $table->dropColumn(['document_number_encrypted', 'document_number_hash', 'document_type']);
        });
    }
};
