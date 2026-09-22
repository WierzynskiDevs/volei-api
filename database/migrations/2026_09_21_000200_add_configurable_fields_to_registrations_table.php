<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Respostas aos campos configuráveis do evento (Q19, ADR 0016).
 *
 * Colunas tipadas, nunca jsonb livre para os VALORES respondidos — só a
 * configuração (quais campos, em `events`) é array; a resposta de cada
 * inscrição tem shape fixo (CLAUDE.md §5).
 *
 * Todas nullable: preenchidas só quando o evento exige ou oferece o campo
 * (`CreateRegistrationAction` decide o que grava, nunca o cliente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->string('emergency_contact_name')->nullable();
            // Contato de terceiro, mas ainda é dado pessoal — mesmo tratamento
            // de telefone do resto do sistema (CLAUDE.md §12): reversível,
            // nunca em claro fora do backend. Sem hash: não há necessidade de
            // dedup/busca por este telefone.
            $table->text('emergency_contact_phone_encrypted')->nullable();
            $table->string('shirt_size', 4)->nullable();
            $table->string('team_name', 60)->nullable();
            $table->string('dietary_restriction', 200)->nullable();
        });

        DB::statement(
            'ALTER TABLE registrations ADD CONSTRAINT registrations_shirt_size_check '
            ."CHECK (shirt_size IS NULL OR shirt_size IN ('PP','P','M','G','GG','XG'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE registrations DROP CONSTRAINT IF EXISTS registrations_shirt_size_check');

        Schema::table('registrations', function (Blueprint $table): void {
            $table->dropColumn([
                'emergency_contact_name',
                'emergency_contact_phone_encrypted',
                'shirt_size',
                'team_name',
                'dietary_restriction',
            ]);
        });
    }
};
