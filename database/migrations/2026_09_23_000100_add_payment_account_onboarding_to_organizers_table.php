<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dado de onboarding da subconta Asaas (ADR 0018) — só o que falta além do
 * que já existe (`name`, `contact_email`, `document_number_*`,
 * `contact_phone_encrypted`, `payment_account_external_id`).
 *
 * `payment_account_api_key_encrypted`: a credencial da SUBCONTA (nunca a
 * nossa), criptografada, nunca logada (CLAUDE.md §21). `payment_account_wallet_id`
 * não é segredo — é identificador de carteira, mesmo tratamento de
 * `payment_account_external_id`.
 *
 * Endereço/renda: exigidos pelo Asaas para abrir a subconta
 * (`docs/asaas.md` §8) — nunca coletados no cadastro inicial (ADR 0014), só
 * no momento em que o organizador pede a vinculação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizers', function (Blueprint $table): void {
            $table->text('payment_account_api_key_encrypted')->nullable();
            $table->string('payment_account_wallet_id')->nullable();

            /*
             * Telefone móvel exigido pelo Asaas para abrir a subconta —
             * `contact_phone_encrypted` existe na tabela mas nenhum fluxo o
             * preenche hoje (não há valor real para reaproveitar). Só
             * `encrypted`, sem hash: diferente do telefone de usuário/juiz,
             * este não precisa de busca nem dedup, só de proteção em
             * repouso (CLAUDE.md §12).
             */
            $table->text('payment_account_mobile_phone_encrypted')->nullable();

            $table->string('payment_account_address')->nullable();
            $table->string('payment_account_address_number', 20)->nullable();
            $table->string('payment_account_province', 120)->nullable();
            $table->string('payment_account_postal_code', 10)->nullable();
            $table->bigInteger('payment_account_income_cents')->nullable();
        });

        DB::statement(
            'ALTER TABLE organizers ADD CONSTRAINT organizers_payment_account_income_check '
            .'CHECK (payment_account_income_cents IS NULL OR payment_account_income_cents >= 0)'
        );
    }

    public function down(): void
    {
        Schema::table('organizers', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_account_api_key_encrypted',
                'payment_account_wallet_id',
                'payment_account_mobile_phone_encrypted',
                'payment_account_address',
                'payment_account_address_number',
                'payment_account_province',
                'payment_account_postal_code',
                'payment_account_income_cents',
            ]);
        });
    }
};
