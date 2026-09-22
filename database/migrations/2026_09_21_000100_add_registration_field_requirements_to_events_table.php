<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de inscrição configuráveis pelo organizador (Q19, ADR 0016).
 *
 * Mesmo padrão de `rules` (jsonb array, sem tabela filha): o catálogo é
 * fechado (`RegistrationFieldKey`) e pequeno, então array com shape validado
 * na aplicação já satisfaz o CLAUDE.md §5 — não precisa de FK por linha.
 *
 * Dois arrays, não um bool por linha: presença em `required` ou `optional`
 * decide se o campo aparece; ausência dos dois = não coletado. Um `field_key`
 * nunca aparece nos dois ao mesmo tempo (validado na aplicação).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->jsonb('required_registration_fields')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('optional_registration_fields')->default(DB::raw("'[]'::jsonb"));
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['required_registration_fields', 'optional_registration_fields']);
        });
    }
};
