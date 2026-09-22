<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usuários, papéis e sessão (BRIEF §14/§15/§16, CLAUDE.md §6/§12).
 *
 * Decisões registradas:
 *  - CPF NÃO é coletado: não existe coluna (BRIEF §15, LGPD minimização).
 *  - Telefone protegido: phone_encrypted + phone_hash (BRIEF §16).
 *  - Papéis em tabela separada — nunca coluna na tabela users (um usuário do
 *    baseline tem PLAYER + ORGANIZER simultaneamente).
 *  - UUID como chave exposta na API: não revela volume de negócio.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A tabela default do Laravel usa id sequencial e não tem os campos do
        // domínio. Recriamos com a estrutura canônica.
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->string('email')->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');

            // LGPD: nunca em claro. Busca e unicidade acontecem pelo hash.
            $table->text('phone_encrypted')->nullable();
            $table->string('phone_hash', 64)->nullable()->unique();

            $table->date('birth_date')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('level', 32)->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();

            $table->string('status', 32)->default('ACTIVE');

            // Consentimento com versão — exigência de finalidade da LGPD.
            // O baseline tem DOIS aceites distintos na tela de cadastro.
            $table->timestampTz('terms_accepted_at')->nullable();
            $table->string('terms_version', 16)->nullable();
            $table->timestampTz('privacy_accepted_at')->nullable();
            $table->string('privacy_version', 16)->nullable();

            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('ACTIVE','BLOCKED'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_state_check CHECK (state IS NULL OR state ~ '^[A-Z]{2}$')");
        // Data de nascimento no futuro é sempre erro de entrada.
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_birth_date_check CHECK (birth_date IS NULL OR birth_date <= CURRENT_DATE)');

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32);
            $table->timestampsTz();

            // Um papel por usuário, uma única vez.
            $table->unique(['user_id', 'role']);
            $table->index('role');
        });

        DB::statement("ALTER TABLE user_roles ADD CONSTRAINT user_roles_role_check CHECK (role IN ('PLAYER','ORGANIZER','SUPER_ADMIN'))");

        // Sessões: o Sanctum em modo cookie (ADR 0005) usa o driver de sessão.
        // A coluna nasce bigint no skeleton do Laravel; com users em UUID ela
        // precisa ser uuid, ou toda sessão autenticada quebra. As tabelas estão
        // vazias neste ponto, então trocamos a coluna em vez de convertê-la.
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('sessions', function (Blueprint $table): void {
            $table->uuid('user_id')->nullable()->index()->after('id');
        });

        // Mesmo motivo em personal_access_tokens: o morph do Sanctum aponta para
        // users. Ainda que o ADR 0005 use cookie, deixar bigint aqui é uma
        // armadilha silenciosa para quem emitir um token depois.
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['tokenable_type', 'tokenable_id']);
            $table->dropColumn('tokenable_id');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('tokenable_id')->after('tokenable_type');
            $table->index(['tokenable_type', 'tokenable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('users');

        // Restaura a tabela mínima do Laravel para que o rollback seja coerente.
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
};
