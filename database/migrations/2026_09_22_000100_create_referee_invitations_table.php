<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token de convite do juiz (ADR 0013 §5 — S8b).
 *
 * Mesmo padrão do reset de senha (ADR 0005): só o HASH do token fica no
 * banco, o valor bruto é devolvido uma única vez na resposta do convite e
 * nunca mais recuperável. Reenviar convite invalida o anterior
 * (`InviteRefereeAction` marca `used_at` no token velho antes de criar o novo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referee_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_referee_id')->constrained('event_referees')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at');

            $table->index('event_referee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referee_invitations');
    }
};
