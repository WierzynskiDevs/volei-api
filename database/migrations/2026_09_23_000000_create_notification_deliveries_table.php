<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fila de notificação transacional (CLAUDE.md §20, B4 do plano de continuação).
 *
 * Não é `audit_logs`: aqui o registro MUDA de status (PENDING → SENT/FAILED,
 * o mesmo padrão de `payment_webhook_events`), porque o valor do dado é saber
 * se a entrega aconteceu, não uma trilha imutável de quem fez o quê.
 *
 * `metadata` é sempre sanitizado antes de gravar (CLAUDE.md §9/§21) — nunca
 * corpo completo do e-mail, nunca telefone em claro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('channel');
            $table->string('status')->default('PENDING');

            $table->foreignUuid('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email')->nullable();

            $table->string('subject');
            $table->jsonb('metadata')->nullable();
            $table->string('error_message')->nullable();
            $table->timestampTz('sent_at')->nullable();

            $table->timestampsTz();

            $table->index('status');
            $table->index('recipient_user_id');
        });

        DB::statement(
            'ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_type_check '
            ."CHECK (type IN ('PARTNER_INVITED','MATCH_READY','REFEREE_INVITED'))"
        );
        DB::statement(
            'ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_channel_check '
            ."CHECK (channel IN ('EMAIL','SMS'))"
        );
        DB::statement(
            'ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_check '
            ."CHECK (status IN ('PENDING','SENT','FAILED'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
