<?php

declare(strict_types=1);

use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Domain\Enums\GenderCategory;
use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;

/*
 * Vitrine pública (`GET /api/v1/events`).
 *
 * Consumidores: /eventos e /eventos/{slug} no volei-app.
 */

describe('GET /api/v1/events', function () {
    it('lista eventos publicados sem exigir autenticação', function () {
        Event::factory()->registrationOpen()->create(['name' => 'Beach Open Floripa']);

        $this->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Beach Open Floripa')
            ->assertJsonPath('meta.total', 1);

        $this->assertGuest();
    });

    it('nunca expõe rascunho de organizador', function () {
        Event::factory()->create(['name' => 'Rascunho secreto']);          // DRAFT
        Event::factory()->registrationOpen()->create(['name' => 'Público']);

        $response = $this->getJson('/api/v1/events')->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('data.0.name'))->toBe('Público');
    });

    it('tira cancelado da vitrine', function () {
        Event::factory()->cancelled()->create();
        Event::factory()->registrationOpen()->create();

        $this->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    });

    it('mantém evento finalizado na vitrine, como o baseline faz', function () {
        Event::factory()->create(['status' => EventStatus::FINISHED, 'name' => 'Etapa encerrada']);

        $this->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Etapa encerrada');
    });

    it('filtra por categoria e por nível', function () {
        Event::factory()->registrationOpen()->create([
            'gender_category' => GenderCategory::FEMALE,
            'level_category' => LevelCategory::OPEN,
        ]);
        Event::factory()->registrationOpen()->create([
            'gender_category' => GenderCategory::MALE,
            'level_category' => LevelCategory::ADVANCED,
        ]);

        $this->getJson('/api/v1/events?gender_category=FEMALE')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.gender_category', 'FEMALE');

        $this->getJson('/api/v1/events?level_category=ADVANCED')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    });

    it('filtra por status dentro do que já é público', function () {
        Event::factory()->registrationOpen()->create(['name' => 'Aberto']);
        Event::factory()->published()->create(['name' => 'Publicado sem inscrição']);

        $this->getJson('/api/v1/events?status=REGISTRATION_OPEN')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Aberto');
    });

    it('não deixa o filtro de status expor rascunho', function () {
        Event::factory()->create(['name' => 'Rascunho secreto']);   // DRAFT

        $this->getJson('/api/v1/events?status=DRAFT')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    });

    it('busca por nome, cidade, arena e formato', function () {
        Event::factory()->registrationOpen()->create([
            'name' => 'Copa Areia Curitiba',
            'city' => 'Curitiba',
            'venue_name' => 'Arena Norte Beach',
        ]);
        Event::factory()->registrationOpen()->create(['name' => 'Outro torneio', 'city' => 'Recife']);

        $this->getJson('/api/v1/events?q=curitiba')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/events?q=Arena Norte')->assertOk()->assertJsonPath('meta.total', 1);
    });

    it('trata curinga de LIKE como texto, não como padrão', function () {
        Event::factory()->registrationOpen()->create(['name' => 'Torneio 100% Areia']);
        Event::factory()->registrationOpen()->create(['name' => 'Nada a ver']);

        $this->getJson('/api/v1/events?q=100%')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    });

    it('pagina e respeita o teto de itens por página', function () {
        Event::factory()->count(5)->registrationOpen()->create();

        $this->getJson('/api/v1/events?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5);

        // per_page absurdo não derruba o servidor com uma lista inteira.
        $this->getJson('/api/v1/events?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 48);
    });

    it('não faz N+1: uma query de organizador para a lista inteira', function () {
        Event::factory()->count(5)->registrationOpen()->create();

        DB::enableQueryLog();
        $this->getJson('/api/v1/events')->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        /*
         * Contar o total de queries mediria também sessão e cache, que variam
         * com a configuração. O que caracteriza N+1 aqui é uma consulta a
         * `organizers` por evento — então é isso que se mede.
         */
        $organizerQueries = collect($log)
            ->filter(fn (array $q): bool => str_contains((string) $q['query'], '"organizers"'))
            ->count();

        expect($organizerQueries)->toBe(1);
    });

    it('ordena por data de início', function () {
        Event::factory()->registrationOpen()->create([
            'name' => 'Depois',
            'start_at' => now()->addMonths(2),
            'end_at' => now()->addMonths(2)->addHours(8),
        ]);
        Event::factory()->registrationOpen()->create([
            'name' => 'Antes',
            'start_at' => now()->addWeek(),
            'end_at' => now()->addWeek()->addHours(8),
        ]);

        $this->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Antes');
    });
});

describe('GET /api/v1/events/{slug}', function () {
    it('devolve a página pública do evento', function () {
        $organizer = Organizer::factory()->create(['name' => 'Arena Norte Beach']);
        Event::factory()->registrationOpen()->forOrganizer($organizer)->create([
            'slug' => 'copa-areia-curitiba',
            'registration_fee_cents' => 13000,
        ]);

        $this->getJson('/api/v1/events/copa-areia-curitiba')
            ->assertOk()
            ->assertJsonPath('data.slug', 'copa-areia-curitiba')
            ->assertJsonPath('data.registration_fee_cents', 13000)
            ->assertJsonPath('data.organizer.name', 'Arena Norte Beach');
    });

    it('mantém a página do evento cancelado acessível pelo link', function () {
        Event::factory()->cancelled()->create(['slug' => 'evento-cancelado']);

        $this->getJson('/api/v1/events/evento-cancelado')
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.cancellation_reason', 'Chuva forte prevista para o fim de semana.');
    });

    it('devolve 404 para rascunho', function () {
        Event::factory()->create(['slug' => 'rascunho-privado']);

        $this->getJson('/api/v1/events/rascunho-privado')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    });

    it('não vaza dado pessoal do organizador', function () {
        $organizer = Organizer::factory()->create(['contact_email' => 'sigilo@arena.com.br']);
        Event::factory()->registrationOpen()->forOrganizer($organizer)->create(['slug' => 'evento-publico']);

        $response = $this->getJson('/api/v1/events/evento-publico')->assertOk();

        expect(json_encode($response->json()))
            ->not->toContain('sigilo@arena.com.br')
            ->and($response->json('data.organizer'))->toHaveKeys(['id', 'name', 'slug'])
            ->and($response->json('data.organizer'))->not->toHaveKey('contact_email');
    });

    it('trafega data em ISO 8601 no fuso do evento, nunca rótulo formatado', function () {
        Event::factory()->registrationOpen()->create(['slug' => 'com-horario']);

        $start = $this->getJson('/api/v1/events/com-horario')->assertOk()->json('data.start_at');

        expect($start)->toEndWith('-03:00')
            ->and($start)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
    });
});
