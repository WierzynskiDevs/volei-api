<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planos e taxas da plataforma (BRIEF §25/§26, CLAUDE.md §7).
 *
 * A taxa da plataforma NUNCA é hardcoded: vive aqui e é congelada na cobrança.
 * Alterar a taxa de um plano não afeta pagamentos já criados (BRIEF §27).
 *
 * Fase 1 implementa a ESTRUTURA (identificar plano, identificar taxa, configurar
 * taxa, congelar taxa). A cobrança da assinatura é Fase 2 (docs/PHASE-2.md §14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('code', 32)->unique();   // FREE | PRO | PREMIUM
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('ACTIVE');

            // Valores monetários em centavos (bigint). Nunca float.
            $table->bigInteger('monthly_price_cents')->default(0);

            // Taxa em basis points: 5% = 500, 3,5% = 350, 2,5% = 250.
            // Inteiro para não haver arredondamento de float na taxa.
            $table->integer('platform_fee_basis_points');
            $table->bigInteger('platform_fee_fixed_cents')->default(0);

            // NULL = ilimitado (o baseline prevê PREMIUM sem limite).
            $table->integer('event_limit')->nullable();
            $table->integer('registration_limit')->nullable();

            $table->jsonb('features')->default(DB::raw("'[]'::jsonb"));
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_monthly_price_check CHECK (monthly_price_cents >= 0)');
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_fee_bp_check CHECK (platform_fee_basis_points >= 0 AND platform_fee_basis_points <= 10000)');
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_fee_fixed_check CHECK (platform_fee_fixed_cents >= 0)');
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_event_limit_check CHECK (event_limit IS NULL OR event_limit >= 0)');
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_registration_limit_check CHECK (registration_limit IS NULL OR registration_limit >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
