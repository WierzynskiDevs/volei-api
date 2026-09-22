<?php

declare(strict_types=1);

use App\Modules\Users\Domain\Enums\PlayerLevel;
use App\Modules\Users\Domain\Enums\UserStatus;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Busca de atletas para formar dupla — consumidores: seletor de parceiro em
 * /inscricao/{slug} e busca de /trocar-dupla.
 */

beforeEach(function (): void {
    $this->viewer = User::factory()->player()->create(['name' => 'Pedro Serra']);
});

describe('GET /api/v1/players', function (): void {

    it('busca por parte do nome', function (): void {
        User::factory()->player()->create([
            'name' => 'Rafael Lima',
            'level' => PlayerLevel::ADVANCED,
            'city' => 'Curitiba',
        ]);
        User::factory()->player()->create(['name' => 'Carla Souza']);

        $this->actingAs($this->viewer)
            ->getJson('/api/v1/players?q=rafa')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Rafael Lima')
            ->assertJsonPath('data.0.level', PlayerLevel::ADVANCED->value)
            ->assertJsonPath('data.0.city', 'Curitiba');
    });

    /*
     * LGPD §12: o contrato é estreito por construção. Uma busca por nome não
     * pode virar um diretório de contatos.
     */
    it('nunca devolve e-mail nem telefone', function (): void {
        User::factory()->player()->create([
            'name' => 'Rafael Lima',
            'email' => 'rafael@exemplo.com',
        ]);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/players?q=rafael')
            ->assertOk();

        expect($response->getContent())->not->toContain('rafael@exemplo.com')
            ->and($response->json('data.0'))->toHaveKeys(['id', 'name', 'level', 'city', 'state'])
            ->and($response->json('data.0'))->not->toHaveKey('email')
            ->and($response->json('data.0'))->not->toHaveKey('phone')
            ->and($response->json('data.0'))->not->toHaveKey('phone_masked');
    });

    /*
     * Anti-enumeração: sem termo mínimo o endpoint seria um dump da base.
     */
    it('devolve vazio para termo curto, sem listar todos', function (string $term): void {
        User::factory()->count(5)->player()->create();

        $this->actingAs($this->viewer)
            ->getJson('/api/v1/players?q='.$term)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    })->with(['', 'a']);

    /*
     * O curinga do LIKE tem de ser tratado como texto (CLAUDE.md §11) — senão
     * `q=%` devolve a base inteira e o termo mínimo não protege nada.
     */
    it('trata curinga de LIKE como texto, não como padrão', function (): void {
        User::factory()->count(5)->player()->create();

        $this->actingAs($this->viewer)
            ->getJson('/api/v1/players?q=%25%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('não devolve o próprio usuário — ninguém forma dupla consigo mesmo', function (): void {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/players?q=Pedro')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('não devolve conta bloqueada', function (): void {
        User::factory()->player()->create([
            'name' => 'Rafael Bloqueado',
            'status' => UserStatus::BLOCKED,
        ]);

        $this->actingAs($this->viewer)
            ->getJson('/api/v1/players?q=Rafael')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('limita o número de resultados', function (): void {
        User::factory()->count(30)->player()->create(['name' => 'Silva Teste']);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/players?q=Silva')
            ->assertOk();

        expect(count($response->json('data')))->toBeLessThanOrEqual(20);
    });

    it('nega anônimo com 401', function (): void {
        $this->getJson('/api/v1/players?q=rafael')->assertUnauthorized();
    });
});
