<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Juízes do evento (ADR 0013 §5/§6/§8 — S8b).
 *
 * Telefone protegido no mesmo padrão do resto do sistema
 * (`phone_encrypted` + `phone_hash`, CLAUDE.md §12) — dedup por evento, não
 * global: o mesmo telefone pode arbitrar em dois eventos diferentes sem
 * colidir, mas não duas vezes no mesmo evento.
 *
 * `user_id` nullable: o juiz pode ter conta própria (loga normal e vê as
 * mesmas partidas) ou nunca ter se cadastrado — o acesso por link
 * (`referee_invitations`) não exige a segunda coisa (ADR 0013 §5).
 *
 * `court_id` com `nullOnDelete`: apagar uma quadra não deve apagar o juiz,
 * só desatribuí-lo — o organizador reatribui depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_referees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('court_id')->nullable()->constrained('courts')->nullOnDelete();

            $table->string('name', 160);
            $table->text('phone_encrypted');
            $table->string('phone_hash', 64);

            $table->string('invite_status', 16)->default('NOT_SENT');
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();

            $table->timestampsTz();

            $table->unique(['event_id', 'phone_hash']);
            $table->index(['event_id', 'court_id']);
        });

        DB::statement(
            'ALTER TABLE event_referees ADD CONSTRAINT event_referees_invite_status_check '
            ."CHECK (invite_status IN ('NOT_SENT','SENT','ACCEPTED'))"
        );
        DB::statement(
            'ALTER TABLE event_referees ADD CONSTRAINT event_referees_accepted_check '
            ."CHECK (invite_status <> 'ACCEPTED' OR accepted_at IS NOT NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('event_referees');
    }
};
