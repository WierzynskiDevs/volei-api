<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organizadores (BRIEF §37, docs/DIVERGENCES.md §7).
 *
 * O baseline usa TRÊS identificadores diferentes para a mesma entidade
 * ("organizer-01", "org-1" e o nome "Arena Norte Beach"), o que torna o join
 * impossível. Aqui existe uma única chave: organizers.id (UUID).
 * NUNCA usar o nome como identificador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Um usuário tem no máximo um perfil de organizador.
            $table->foreignUuid('user_id')->unique()->constrained('users')->restrictOnDelete();

            $table->string('name');            // nome público ("Arena Norte Beach")
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('contact_phone_encrypted')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();

            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();

            // Situação financeira/administrativa (o baseline prevê BLOQUEADO).
            $table->string('status', 32)->default('REGULAR');

            // Conta no gateway. Sem ela o organizador não publica evento pago
            // (docs/OPEN-QUESTIONS.md Q6) — não se cobra sem destino do dinheiro.
            $table->string('payment_account_status', 32)->default('NOT_LINKED');
            $table->string('payment_account_external_id')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
            $table->index('plan_id');
        });

        DB::statement("ALTER TABLE organizers ADD CONSTRAINT organizers_status_check CHECK (status IN ('REGULAR','ATTENTION','BLOCKED'))");
        DB::statement("ALTER TABLE organizers ADD CONSTRAINT organizers_payment_account_status_check CHECK (payment_account_status IN ('LINKED','PENDING','NOT_LINKED'))");
        DB::statement("ALTER TABLE organizers ADD CONSTRAINT organizers_state_check CHECK (state IS NULL OR state ~ '^[A-Z]{2}$')");

        /**
         * Histórico de plano (o baseline exibe `planHistory` em /admin/organizadores/$id).
         * Append-only: é a evidência de qual taxa valia em cada período.
         */
        Schema::create('organizer_plan_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();

            // Taxa vigente no momento da troca — congelada, para auditoria.
            $table->integer('platform_fee_basis_points');
            $table->text('note')->nullable();
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('effective_at');
            $table->timestampTz('created_at');

            $table->index(['organizer_id', 'effective_at']);
        });

        DB::statement('ALTER TABLE organizer_plan_history ADD CONSTRAINT oph_fee_bp_check CHECK (platform_fee_basis_points >= 0 AND platform_fee_basis_points <= 10000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_plan_history');
        Schema::dropIfExists('organizers');
    }
};
