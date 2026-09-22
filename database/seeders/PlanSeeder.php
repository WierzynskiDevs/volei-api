<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Organizers\Infrastructure\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Planos da plataforma (BRIEF §26).
 *
 * Os valores espelham `src/lib/finance-data.ts` do baseline. Este seeder é
 * idempotente: rodar de novo não duplica nem sobrescreve taxa já ajustada em
 * produção — a chave é `code`.
 *
 * ATENÇÃO: alterar `platform_fee_basis_points` aqui NÃO altera cobranças já
 * emitidas. A taxa é congelada no pagamento (BRIEF §27).
 */
final class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'FREE',
                'name' => 'FREE',
                'description' => 'Para quem está começando a organizar etapas e torneios sociais.',
                'monthly_price_cents' => 0,
                'platform_fee_basis_points' => 500,   // 5%
                'platform_fee_fixed_cents' => 0,
                'event_limit' => 3,
                'registration_limit' => 120,
                'sort_order' => 1,
                'features' => [
                    'Inscrições online',
                    'Chaves e resultados',
                    'Ranking de performance',
                    'Suporte por e-mail',
                ],
            ],
            [
                'code' => 'PRO',
                'name' => 'PRO',
                'description' => 'Circuitos recorrentes com várias etapas por temporada.',
                'monthly_price_cents' => 14900,
                'platform_fee_basis_points' => 350,   // 3,5%
                'platform_fee_fixed_cents' => 0,
                'event_limit' => 20,
                'registration_limit' => 2000,
                'sort_order' => 2,
                'features' => [
                    'Tudo do FREE',
                    'Etapas ilimitadas por circuito',
                    'Página do circuito',
                    'Relatórios financeiros por evento',
                    'Lembretes automáticos de cobrança',
                ],
            ],
            [
                'code' => 'PREMIUM',
                'name' => 'PREMIUM',
                'description' => 'Operação profissional com times, arenas próprias e alto volume.',
                'monthly_price_cents' => 39900,
                'platform_fee_basis_points' => 250,   // 2,5%
                'platform_fee_fixed_cents' => 0,
                'event_limit' => null,                // ilimitado
                'registration_limit' => null,
                'sort_order' => 3,
                'features' => [
                    'Tudo do PRO',
                    'Eventos e inscrições ilimitados',
                    'Múltiplos operadores',
                    'Exportação contábil',
                    'Suporte prioritário',
                ],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::firstOrCreate(
                ['code' => $plan['code']],
                $plan + ['status' => 'ACTIVE'],
            );
        }
    }
}
