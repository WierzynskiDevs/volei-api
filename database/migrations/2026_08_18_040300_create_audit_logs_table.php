<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha de auditoria (BRIEF §41, CLAUDE.md §9).
 *
 * APPEND-ONLY. Sem updated_at, sem deleted_at: um registro de auditoria não é
 * corrigido nem apagado. Existe desde o dia 1 — auditoria retroativa não existe.
 *
 * NUNCA armazenar senha, token, API key ou dado de cartão em `metadata`
 * (CLAUDE.md §9 e §21). A sanitização acontece antes da gravação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Nulo quando a ação é do sistema (job, webhook) e não de uma pessoa.
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 32)->nullable();

            $table->string('action', 64);

            // Alvo polimórfico por string — o alvo pode ser apagado (soft delete)
            // sem que a trilha perca sentido.
            $table->string('target_type', 64)->nullable();
            $table->uuid('target_id')->nullable();

            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));

            $table->string('ip', 45)->nullable();          // IPv6 cabe em 45
            $table->text('user_agent')->nullable();
            $table->uuid('request_id')->nullable();         // correlação request→job→webhook

            $table->timestampTz('created_at');

            $table->index('action');
            $table->index('actor_id');
            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });

        // Barreira de banco contra alteração da trilha. Uma trilha de auditoria
        // que pode ser editada não é uma trilha de auditoria.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_deny_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs é append-only: % não é permitido', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_deny_mutation();

            CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_deny_mutation();

            -- Triggers de linha não pegam TRUNCATE: sem esta guarda, a trilha
            -- inteira poderia ser zerada com um comando.
            CREATE TRIGGER audit_logs_no_truncate
                BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_deny_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete ON audit_logs');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_deny_mutation()');

        Schema::dropIfExists('audit_logs');
    }
};
