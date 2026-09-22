<?php

declare(strict_types=1);

use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Registrations\Application\EventOccupancy;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Concorrência na ocupação de vaga (CLAUDE.md §8 e §22, ADR 0003).
 *
 * ## O que este arquivo prova, e o que NÃO prova
 *
 * A suíte roda dentro de uma transação (`RefreshDatabase`), então uma segunda
 * conexão não veria os dados do teste. Isso torna impossível executar duas
 * requisições **de fato paralelas** aqui — e um teste que finge paralelismo é
 * pior que nenhum, porque passa a falsa sensação de cobertura.
 *
 * O que é verificado, então, são as três camadas que tornam a corrida
 * inofensiva, cada uma isoladamente:
 *
 *  1. o lock pessimista é realmente emitido (`SELECT ... FOR UPDATE`);
 *  2. o índice unique parcial recusa a duplicidade no **banco**, independente
 *     da aplicação — é a última linha de defesa;
 *  3. a contagem sob lock recusa a vaga além do teto, e o duplo clique
 *     sequencial devolve 409 em vez de criar dois registros.
 *
 * Verificação de paralelismo real fica para teste de carga fora da suíte.
 */

beforeEach(function (): void {
    $this->athlete = User::factory()->player()->create();
    $this->event = Event::factory()->registrationOpen()->create([
        'level_category' => LevelCategory::FREE,
        'max_teams' => 2,
    ]);
});

describe('camada 1 — lock pessimista', function (): void {

    /*
     * Guarda de regressão: se alguém remover o `lockForUpdate`, a corrida da
     * última vaga volta a existir e nenhum outro teste percebe, porque em
     * execução sequencial o resultado é igual.
     */
    it('trava a linha do evento com SELECT ... FOR UPDATE', function (): void {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = mb_strtolower($query->sql);
        });

        app(EventOccupancy::class)->lock($this->event->id);

        $locking = array_filter(
            $statements,
            fn (string $sql): bool => str_contains($sql, 'from "events"') && str_contains($sql, 'for update'),
        );

        expect($locking)->not->toBeEmpty('a contagem de vagas precisa acontecer sob lock da linha do evento');
    });

    it('devolve o evento relido do banco, não o objeto passado', function (): void {
        DB::table('events')->where('id', $this->event->id)->update(['teams_registered_count' => 7]);

        $locked = app(EventOccupancy::class)->lock($this->event->id);

        // O `$this->event` em memória ainda tem 0; o lock precisa trazer o real.
        expect($locked->teams_registered_count)->toBe(7)
            ->and($this->event->teams_registered_count)->toBe(0);
    });
});

describe('camada 2 — o banco é a última linha de defesa', function (): void {

    /*
     * Simula a corrida perfeita: duas requisições passam pela checagem da
     * aplicação no mesmo instante. O insert vai direto ao banco, sem passar
     * pela action, justamente para provar que a garantia não depende dela.
     */
    it('o índice unique parcial recusa segunda inscrição ativa do mesmo atleta', function (): void {
        Registration::factory()->forEvent($this->event)->forUser($this->athlete)->create();

        $duplicate = fn (): bool => DB::table('registrations')->insert([
            'id' => (string) Str::uuid7(),
            'event_id' => $this->event->id,
            'user_id' => $this->athlete->id,
            'group_id' => null,
            'is_captain' => false,
            'partner_mode' => 'INDIVIDUAL',
            'status' => 'PENDING_PAYMENT',
            'level_review' => 'NOT_REQUIRED',
            'player_level' => null,
            'event_level' => 'FREE',
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);

        expect($duplicate)->toThrow(QueryException::class);
    });

    /*
     * O unique é PARCIAL de propósito (ADR 0003): quem cancelou ou expirou tem
     * de poder voltar. Se o índice fosse total, o atleta ficaria preso ao
     * primeiro cancelamento para sempre.
     */
    it('o unique parcial NÃO impede nova inscrição após cancelamento', function (): void {
        Registration::factory()->forEvent($this->event)->forUser($this->athlete)->cancelled()->create();

        expect(fn (): Registration => Registration::factory()
            ->forEvent($this->event)
            ->forUser($this->athlete)
            ->create()
        )->not->toThrow(QueryException::class);
    });

    it('permite duas inscrições canceladas do mesmo atleta no mesmo evento', function (): void {
        Registration::factory()->count(2)->forEvent($this->event)->forUser($this->athlete)->cancelled()->create();

        expect(Registration::query()->where('user_id', $this->athlete->id)->count())->toBe(2);
    });
});

describe('camada 3 — última vaga e duplo clique', function (): void {

    $payload = ['partner_mode' => 'INDIVIDUAL', 'accept_rules' => true];

    /*
     * A última vaga sai UMA vez. Em execução sequencial isto verifica a
     * contagem sob lock; o lock em si está coberto na camada 1.
     */
    it('a última vaga é entregue a apenas um atleta', function () use ($payload): void {
        Registration::factory()->forEvent($this->event)->confirmed()->create();   // 1 de 2

        $first = User::factory()->player()->create();
        $second = User::factory()->player()->create();

        $this->actingAs($first)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", $payload)
            ->assertCreated();

        $this->actingAs($second)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REGISTRATION_EVENT_FULL');

        expect($this->event->fresh()->teams_registered_count)->toBe(2);
    });

    /** Duplo clique no botão "Inscrever dupla": um 201 e um 409, nunca dois registros. */
    it('duplo clique não cria duas inscrições', function () use ($payload): void {
        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", $payload)
            ->assertCreated();

        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REGISTRATION_DUPLICATE');

        expect(Registration::query()->where('user_id', $this->athlete->id)->count())->toBe(1)
            ->and($this->event->fresh()->teams_registered_count)->toBe(1);
    });

    /*
     * O contador é RECOMPUTADO sob lock, não incrementado. Este teste prova que
     * ele converge para o real mesmo partindo de um valor errado — o que
     * aconteceria com qualquer `+1` perdido por exceção no meio do caminho.
     */
    it('o contador converge para o real mesmo partindo de valor divergente', function () use ($payload): void {
        DB::table('events')->where('id', $this->event->id)->update(['teams_registered_count' => 99]);

        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", $payload)
            ->assertCreated();

        expect($this->event->fresh()->teams_registered_count)->toBe(1);
    });

    /*
     * Uma dupla é uma vaga. Num evento de 2 vagas, duas duplas cabem — se o
     * parceiro pendente contasse, a segunda dupla levaria 409.
     */
    it('duas duplas cabem num evento de duas vagas', function (): void {
        $captainA = User::factory()->player()->create();
        $captainB = User::factory()->player()->create();

        $this->actingAs($captainA)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", [
                'partner_mode' => 'PARTNER',
                'partner_user_id' => User::factory()->player()->create()->id,
                'accept_rules' => true,
            ])
            ->assertCreated();

        $this->actingAs($captainB)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", [
                'partner_mode' => 'PARTNER',
                'partner_user_id' => User::factory()->player()->create()->id,
                'accept_rules' => true,
            ])
            ->assertCreated();

        expect($this->event->fresh()->teams_registered_count)->toBe(2);
    });
});
