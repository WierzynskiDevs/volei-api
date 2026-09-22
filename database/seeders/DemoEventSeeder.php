<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Events\Domain\Enums\AgeCategory;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Domain\Enums\EventType;
use App\Modules\Events\Domain\Enums\GenderCategory;
use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Events\Domain\Enums\Modality;
use App\Modules\Events\Domain\EventTimezone;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Eventos de demonstração — espelham `events` de `src/lib/mock-data.ts`.
 *
 * Os seis eventos do protótipo passam a existir no banco com o mesmo slug,
 * nome, arena, cidade, formato e contagem de duplas. A tela continua parecendo
 * a mesma; o que muda é a origem do dado.
 *
 * Três correções conscientes em relação ao mock, todas já registradas:
 *
 *  1. **Preço.** `copa-areia-curitiba` anunciava "R$ 120 / dupla" no card e
 *     cobrava R$ 130 no checkout (docs/DIVERGENCES.md §5). Aqui existe um único
 *     `registration_fee_cents` e ele vale 13000 — o valor que o dinheiro do
 *     baseline realmente usava.
 *
 *  2. **Hora de início.** No mock a hora só existia dentro da string
 *     `dateLabel` ("22 ago · sáb · 08h30"). Agora é dado: hora de parede
 *     convertida para UTC pelo fuso do evento (ADR 0006).
 *
 *  3. **Dono do evento.** O mock referencia o organizador por nome e chama de
 *     "Federação Litoral" o dono do evento "Circuito Litoral — Etapa 3"
 *     (docs/DIVERGENCES.md §7). Aqui o evento pertence ao organizador
 *     Circuito Litoral, que é quem tem conta — sem isso, a conta de
 *     demonstração com dois papéis entraria num painel vazio.
 *
 * Os estados AWAITING_DRAW e IN_PROGRESS são de Fase 2 e não têm transição
 * implementada (docs/DIVERGENCES.md §4). O seeder os grava diretamente porque
 * a `EventStatusPill` do baseline os exibe e a tela precisa continuar
 * mostrando o mesmo conjunto de estados.
 *
 * Idempotente: a chave é o slug.
 */
final class DemoEventSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoEventSeeder não roda em produção: cria dados fictícios.');
        }

        $tz = EventTimezone::default();
        $now = CarbonImmutable::now();

        foreach ($this->events() as $data) {
            $organizer = Organizer::query()->where('name', $data['organizer'])->first();

            if (! $organizer instanceof Organizer) {
                throw new RuntimeException(
                    "Organizador \"{$data['organizer']}\" não existe. Rode o DemoAccountSeeder antes."
                );
            }

            $event = Event::query()->where('slug', $data['slug'])->first();

            if (! $event instanceof Event) {
                $event = new Event;
                $event->slug = $data['slug'];
                $event->organizer_id = $organizer->id;
            }

            $event->fill([
                'name' => $data['name'],
                'description' => $data['description'],
                'venue_name' => $data['venue'],
                'city' => $data['city'],
                'state' => $data['state'],
                'timezone' => $tz->name,
                'start_at' => $tz->toUtc($data['date'], $data['start']),
                'end_at' => $tz->toUtc($data['date'], $data['end']),
                'registration_open_at' => null,
                'registration_close_at' => $tz->toUtc($data['close_date'], $data['close_time']),
                'registration_fee_cents' => $data['fee_cents'],
                'max_teams' => $data['max_teams'],
                'courts' => $data['courts'],
                'min_games' => $data['min_games'],
                'format' => $data['format'],
                'modality' => $data['modality'],
                'gender_category' => $data['gender'],
                'level_category' => $data['level'],
                'age_category' => AgeCategory::ADULT,
                'event_type' => $data['type'],
                'prize_description' => $data['prize'],
                'rules' => $data['rules'],
            ]);

            // Estado e contagem não são $fillable: são consequência de ação,
            // não de formulário. No seeder são atribuídos explicitamente.
            $event->forceFill([
                'status' => $data['status'],
                'teams_registered_count' => $data['teams'],
                'published_at' => $data['status'] === EventStatus::DRAFT ? null : $now,
            ])->save();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function events(): array
    {
        return [
            [
                'slug' => 'copa-areia-curitiba',
                'name' => 'Copa Areia Curitiba',
                'organizer' => 'Arena Norte Beach',
                'venue' => 'Arena Norte Beach',
                'city' => 'Curitiba',
                'state' => 'PR',
                'date' => '2026-08-22',
                'start' => '08:30',
                'end' => '18:00',
                'close_date' => '2026-08-18',
                'close_time' => '23:59',
                'fee_cents' => 13000,
                'max_teams' => 16,
                'teams' => 16,
                'courts' => 4,
                'min_games' => 3,
                'format' => 'Pool Play + Gold/Silver',
                'modality' => Modality::TWO_VS_TWO,
                'gender' => GenderCategory::MALE,
                'level' => LevelCategory::ADVANCED,
                'type' => EventType::RANKING,
                'status' => EventStatus::IN_PROGRESS,
                'prize' => 'R$ 3.000 + troféus',
                'description' => null,
                'rules' => [
                    'Melhor de 3 sets — sets 1 e 2 até 21, terceiro set até 15, diferença mínima de 2 pontos.',
                    'Fase de grupos: set único até 21 com diferença de 2.',
                    'Troca de lado a cada 7 pontos (5 no tie-break).',
                    'Tolerância de 10 minutos por partida; após isso, W.O.',
                ],
            ],
            [
                'slug' => 'beach-open-floripa',
                'name' => 'Beach Open Floripa',
                'organizer' => 'Praia Mole Sports',
                'venue' => 'Praia Mole',
                'city' => 'Florianópolis',
                'state' => 'SC',
                'date' => '2026-09-05',
                'start' => '09:00',
                'end' => '19:00',
                'close_date' => '2026-09-02',
                'close_time' => '20:00',
                'fee_cents' => 9000,
                'max_teams' => 16,
                'teams' => 11,
                'courts' => 3,
                'min_games' => 2,
                'format' => 'Single Elimination',
                'modality' => Modality::TWO_VS_TWO,
                'gender' => GenderCategory::FEMALE,
                'level' => LevelCategory::OPEN,
                'type' => EventType::COMPETITIVE,
                'status' => EventStatus::REGISTRATION_OPEN,
                'prize' => 'R$ 2.000',
                'description' => null,
                'rules' => [
                    'Chave de 16 com BYES automáticos para duplas cabeças de chave.',
                    'Melhor de 3 sets a partir das quartas de final.',
                    'Fases iniciais em set único até 21.',
                ],
            ],
            [
                'slug' => 'americano-de-verao-santos',
                'name' => 'Americano de Verão',
                'organizer' => 'Santos Beach Club',
                'venue' => 'Quadras da Ponta da Praia',
                'city' => 'Santos',
                'state' => 'SP',
                'date' => '2026-09-12',
                'start' => '15:00',
                'end' => '21:00',
                'close_date' => '2026-09-10',
                'close_time' => '18:00',
                'fee_cents' => 4500,
                'max_teams' => 24,
                'teams' => 18,
                'courts' => 3,
                'min_games' => 4,
                'format' => 'Americano',
                'modality' => Modality::TWO_VS_TWO_ROTATING,
                'gender' => GenderCategory::MIXED,
                'level' => LevelCategory::FREE,
                'type' => EventType::SOCIAL,
                'status' => EventStatus::REGISTRATION_OPEN,
                'prize' => 'Kit + medalhas',
                'description' => null,
                'rules' => [
                    'Inscrição individual — parceiros mudam a cada rodada.',
                    'Pontuação individual acumulada.',
                    'Partidas de 12 minutos corridos.',
                ],
            ],
            [
                'slug' => 'king-of-the-court-natal',
                'name' => 'King of the Court Natal',
                'organizer' => 'Duna Beach Arena',
                'venue' => 'Duna Beach Arena',
                'city' => 'Natal',
                'state' => 'RN',
                'date' => '2026-09-19',
                'start' => '16:00',
                'end' => '22:00',
                'close_date' => '2026-09-17',
                'close_time' => '12:00',
                'fee_cents' => 6000,
                'max_teams' => 10,
                'teams' => 10,
                'courts' => 1,
                'min_games' => 3,
                'format' => 'King of the Court',
                'modality' => Modality::TWO_VS_TWO,
                'gender' => GenderCategory::OPEN,
                'level' => LevelCategory::ADVANCED,
                'type' => EventType::SPECIAL,
                'status' => EventStatus::AWAITING_DRAW,
                'prize' => 'R$ 1.500',
                'description' => null,
                'rules' => [
                    'Lado vencedor e lado desafiante — apenas o lado vencedor pontua.',
                    'Rodadas de 5 minutos.',
                    'Ranking individual por pontos acumulados.',
                ],
            ],
            [
                'slug' => 'circuito-litoral-etapa-3',
                'name' => 'Circuito Litoral — Etapa 3',
                'organizer' => 'Circuito Litoral',
                'venue' => 'Arena Boa Viagem',
                'city' => 'Recife',
                'state' => 'PE',
                'date' => '2026-07-18',
                'start' => '08:00',
                'end' => '20:00',
                'close_date' => '2026-07-15',
                'close_time' => '23:59',
                'fee_cents' => 14000,
                'max_teams' => 24,
                'teams' => 24,
                'courts' => 6,
                'min_games' => 3,
                'format' => 'Pool Play + Single Elimination',
                'modality' => Modality::TWO_VS_TWO,
                'gender' => GenderCategory::MALE,
                'level' => LevelCategory::OPEN,
                'type' => EventType::LEAGUE,
                'status' => EventStatus::FINISHED,
                'prize' => 'R$ 5.000',
                'description' => null,
                'rules' => ['Regulamento oficial do Circuito Litoral 2026, versão 2.1.'],
            ],
            [
                'slug' => 'blind-draw-vitoria',
                'name' => 'Blind Draw Vitória',
                'organizer' => 'Camburi Beach',
                'venue' => 'Praia de Camburi',
                'city' => 'Vitória',
                'state' => 'ES',
                'date' => '2026-09-27',
                'start' => '08:00',
                'end' => '14:00',
                'close_date' => '2026-09-25',
                'close_time' => '23:59',
                'fee_cents' => 3500,
                'max_teams' => 20,
                'teams' => 14,
                'courts' => 2,
                'min_games' => 3,
                'format' => 'Blind Draw',
                'modality' => Modality::TWO_VS_TWO,
                'gender' => GenderCategory::MIXED,
                'level' => LevelCategory::BEGINNER,
                'type' => EventType::SOCIAL,
                'status' => EventStatus::REGISTRATION_OPEN,
                'prize' => 'Medalhas',
                'description' => null,
                'rules' => ['Sorteio balanceado por nível. Parceiro definido no momento do sorteio.'],
            ],
        ];
    }
}
