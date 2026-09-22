<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fecha o conjunto de valores de nível nas duas pontas.
 *
 * Dois problemas resolvidos aqui:
 *
 *  1. `events.level_category` não aceitava `A_PLUS_B`. O aditivo §15 pede a
 *     categoria e a decisão está registrada em OPEN-QUESTIONS Q11.
 *
 *  2. `users.level` era `varchar(32)` **sem CHECK**, e o `DemoAccountSeeder`
 *     gravava rótulo em português ("Avançado", "Intermediário", "Iniciante") —
 *     exatamente a "string solta onde existe enum" que CLAUDE.md §5 proíbe.
 *     Comparar esse valor com `events.level_category` (que usa SCREAMING_SNAKE)
 *     nunca daria certo, e é essa comparação que decide se a inscrição vai para
 *     análise do organizador (aditivo §17).
 *
 * A normalização roda ANTES do CHECK, senão o CHECK falha sobre os dados
 * existentes. Migration reversível: o `down()` desfaz o mapeamento.
 */
return new class extends Migration
{
    /**
     * Rótulo do baseline → valor do enum `PlayerLevel`.
     *
     * Escrito literalmente em vez de derivado do enum de propósito: migration é
     * registro histórico e não pode mudar de comportamento porque alguém
     * renomeou um `case` seis meses depois.
     */
    private const array LABEL_TO_VALUE = [
        'Iniciante' => 'BEGINNER',
        'Intermediário' => 'INTERMEDIATE',
        'Avançado' => 'ADVANCED',
        'Open' => 'OPEN',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_level_check');
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_level_check CHECK (level_category IN ('BEGINNER','INTERMEDIATE','ADVANCED','OPEN','A_PLUS_B','FREE'))");

        foreach (self::LABEL_TO_VALUE as $label => $value) {
            DB::update('UPDATE users SET level = ? WHERE level = ?', [$value, $label]);
        }

        /*
         * Qualquer resto não mapeado vira NULL em vez de quebrar a migration.
         * NULL é semanticamente honesto — "nível não declarado" — e o domínio já
         * trata esse caso: `LevelCompatibility` não exige análise sem nível.
         */
        DB::update(
            'UPDATE users SET level = NULL WHERE level IS NOT NULL AND level NOT IN (?, ?, ?, ?)',
            array_values(self::LABEL_TO_VALUE),
        );

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_level_check CHECK (level IS NULL OR level IN ('BEGINNER','INTERMEDIATE','ADVANCED','OPEN'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_level_check');

        foreach (self::LABEL_TO_VALUE as $label => $value) {
            DB::update('UPDATE users SET level = ? WHERE level = ?', [$label, $value]);
        }

        /*
         * Evento em A+B volta para FREE: é a categoria sem teto mais próxima, e
         * a única que não inventa restrição de nível que o organizador não pediu.
         */
        DB::update("UPDATE events SET level_category = 'FREE' WHERE level_category = 'A_PLUS_B'");

        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_level_check');
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_level_check CHECK (level_category IN ('BEGINNER','INTERMEDIATE','ADVANCED','OPEN','FREE'))");
    }
};
