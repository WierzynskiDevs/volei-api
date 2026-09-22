<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Ordem importa: os planos são dado de referência de produção; as contas e os
 * eventos de demonstração dependem deles e existem apenas fora de produção
 * (cada um dos dois recusa rodar em produção).
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
        ]);

        if (! app()->isProduction()) {
            $this->call([
                DemoAccountSeeder::class,
                DemoEventSeeder::class,
            ]);
        }
    }
}
