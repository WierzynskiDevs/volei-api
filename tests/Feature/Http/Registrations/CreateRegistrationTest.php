<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Registrations\Domain\Enums\LevelReview;
use App\Modules\Registrations\Domain\Enums\RegistrationStatus;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Users\Domain\Enums\PlayerLevel;
use App\Modules\Users\Domain\Enums\UserStatus;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Criação de inscrição — consumidor: /inscricao/{slug}.
 *
 * Cobre o exigido por CLAUDE.md §22: caminho feliz, validação, 401 anônimo,
 * 409 conflito (duplicidade, evento cheio, evento fechado) e a análise de nível
 * do aditivo §17.
 */

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'partner_mode' => 'INDIVIDUAL',
        'accept_rules' => true,
    ], $overrides);
}

beforeEach(function (): void {
    // `player()`: um atleta real sempre tem papel, e é o papel que a trilha de
    // auditoria registra.
    $this->athlete = User::factory()->player()->create(['level' => PlayerLevel::INTERMEDIATE]);
    $this->event = Event::factory()->registrationOpen()->create([
        'level_category' => LevelCategory::ADVANCED,
        'max_teams' => 4,
        'registration_fee_cents' => 13000,
    ]);
});

describe('POST /api/v1/events/{slug}/registrations', function (): void {

    it('cria inscrição individual aguardando pagamento, com reserva de vaga', function (): void {
        $response = $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', RegistrationStatus::PENDING_PAYMENT->value)
            ->assertJsonPath('data.partner_mode', 'INDIVIDUAL')
            ->assertJsonPath('data.level_review', LevelReview::NOT_REQUIRED->value)
            ->assertJsonPath('data.player.id', $this->athlete->id);

        // ADR 0003: a vaga fica reservada durante o checkout.
        expect($response->json('data.reserved_until'))->not->toBeNull()
            ->and($response->json('data.confirmed_at'))->toBeNull();
    });

    /*
     * ADR 0003: a ocupação passa a valer já na reserva, senão duas pessoas
     * levariam a mesma última vaga enquanto nenhuma tivesse pagado.
     */
    it('conta a vaga no contador do evento assim que reserva', function (): void {
        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload())
            ->assertCreated();

        expect($this->event->fresh()->teams_registered_count)->toBe(1);
    });

    /*
     * Evento gratuito não tem pagamento para esperar, e a tela tem o botão
     * "Confirmar inscrição gratuita".
     */
    it('confirma na hora quando o evento é gratuito', function (): void {
        $free = Event::factory()->registrationOpen()->free()->create([
            'level_category' => LevelCategory::FREE,
        ]);

        $response = $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$free->slug}/registrations", registrationPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', RegistrationStatus::CONFIRMED->value);

        expect($response->json('data.confirmed_at'))->not->toBeNull()
            ->and($response->json('data.reserved_until'))->toBeNull();
    });

    it('cria dupla com duas inscrições, e o parceiro fica aguardando aceite', function (): void {
        $partner = User::factory()->create(['level' => PlayerLevel::INTERMEDIATE]);

        $response = $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload([
                'partner_mode' => 'PARTNER',
                'partner_user_id' => $partner->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.is_captain', true);

        $groupId = $response->json('data.group_id');
        expect($groupId)->not->toBeNull();

        $partnerRegistration = Registration::query()
            ->where('group_id', $groupId)
            ->where('user_id', $partner->id)
            ->sole();

        expect($partnerRegistration->status)->toBe(RegistrationStatus::PENDING_ACCEPTANCE)
            ->and($partnerRegistration->is_captain)->toBeFalse();
    });

    /*
     * ADR 0001: a dupla nasce com duas inscrições mas consome UMA vaga. Se o
     * parceiro pendente contasse, um evento de 4 duplas caberia só 2.
     */
    it('conta a dupla como UMA vaga, não duas', function (): void {
        $partner = User::factory()->create();

        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload([
                'partner_mode' => 'PARTNER',
                'partner_user_id' => $partner->id,
            ]))
            ->assertCreated();

        expect($this->event->fresh()->teams_registered_count)->toBe(1);
    });
});

describe('POST /api/v1/events/{slug}/registrations — análise de nível (aditivo §17)', function (): void {

    it('marca análise necessária quando o atleta está acima da categoria', function (): void {
        $strong = User::factory()->create(['level' => PlayerLevel::OPEN]);
        $event = Event::factory()->registrationOpen()->create([
            'level_category' => LevelCategory::INTERMEDIATE,
        ]);

        $this->actingAs($strong)
            ->postJson("/api/v1/events/{$event->slug}/registrations", registrationPayload())
            ->assertCreated()
            ->assertJsonPath('data.level_review', LevelReview::REQUIRED->value)
            // Snapshot: o nível pode mudar depois e não pode reescrever a base
            // da decisão do organizador.
            ->assertJsonPath('data.player_level', PlayerLevel::OPEN->value)
            ->assertJsonPath('data.event_level', LevelCategory::INTERMEDIATE->value);
    });

    /*
     * O ponto central do §17 e o texto que a tela já promete ao atleta:
     * "a inscrição segue normalmente — nada é bloqueado automaticamente".
     */
    it('NÃO bloqueia a inscrição: ela segue para pagamento em paralelo', function (): void {
        $strong = User::factory()->create(['level' => PlayerLevel::OPEN]);
        $event = Event::factory()->registrationOpen()->create([
            'level_category' => LevelCategory::BEGINNER,
        ]);

        $this->actingAs($strong)
            ->postJson("/api/v1/events/{$event->slug}/registrations", registrationPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', RegistrationStatus::PENDING_PAYMENT->value)
            ->assertJsonPath('data.level_review', LevelReview::REQUIRED->value);

        // E ocupa vaga normalmente — reprovar depois é decisão do organizador.
        expect($event->fresh()->teams_registered_count)->toBe(1);
    });

    it('não exige análise em categoria sem teto', function (): void {
        $strong = User::factory()->create(['level' => PlayerLevel::OPEN]);
        $event = Event::factory()->registrationOpen()->create([
            'level_category' => LevelCategory::A_PLUS_B,
        ]);

        $this->actingAs($strong)
            ->postJson("/api/v1/events/{$event->slug}/registrations", registrationPayload())
            ->assertCreated()
            ->assertJsonPath('data.level_review', LevelReview::NOT_REQUIRED->value);
    });
});

describe('POST /api/v1/events/{slug}/registrations — conflitos', function (): void {

    it('recusa segunda inscrição do mesmo atleta com 409', function (): void {
        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload())
            ->assertCreated();

        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REGISTRATION_DUPLICATE');
    });

    /*
     * ADR 0003: estado terminal não bloqueia. Quem cancelou tem de poder voltar
     * enquanto houver vaga — é por isso que o unique no banco é parcial.
     */
    it('permite nova inscrição depois de cancelar a anterior', function (): void {
        Registration::factory()
            ->forEvent($this->event)
            ->forUser($this->athlete)
            ->cancelled()
            ->create();

        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload())
            ->assertCreated();
    });

    it('recusa inscrição quando as vagas acabaram, com 409', function (): void {
        // Enche o evento: 4 vagas, 4 inscrições confirmadas sem dupla.
        Registration::factory()
            ->count(4)
            ->forEvent($this->event)
            ->confirmed()
            ->create();

        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REGISTRATION_EVENT_FULL');
    });

    /*
     * Reserva vencida não ocupa vaga (ADR 0003). Sem isso, um evento ficaria
     * permanentemente cheio de gente que nunca pagou.
     */
    it('libera vaga cuja reserva venceu', function (): void {
        Registration::factory()
            ->count(4)
            ->forEvent($this->event)
            ->reservationExpired()
            ->create();

        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload())
            ->assertCreated();
    });

    it('ignora o teto quando o evento não tem limite de duplas', function (): void {
        $unlimited = Event::factory()->registrationOpen()->withoutTeamLimit()->create([
            'level_category' => LevelCategory::FREE,
        ]);

        Registration::factory()->count(30)->forEvent($unlimited)->confirmed()->create();

        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$unlimited->slug}/registrations", registrationPayload())
            ->assertCreated();
    });

    it('recusa inscrição em evento que não aceita, com 409', function (): void {
        $draft = Event::factory()->create();   // DRAFT

        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$draft->slug}/registrations", registrationPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REGISTRATION_EVENT_CLOSED');
    });

    /*
     * A janela publicada vale por si: um evento que ficou REGISTRATION_OPEN mas
     * cujo prazo passou não pode continuar vendendo vaga.
     */
    it('recusa inscrição depois do prazo publicado, mesmo com status aberto', function (): void {
        $closed = Event::factory()->registrationOpen()->create([
            'registration_close_at' => CarbonImmutable::now()->subDay(),
        ]);

        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$closed->slug}/registrations", registrationPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.details.reason', 'registration_window_closed');
    });

    it('recusa inscrição antes da abertura da janela', function (): void {
        $future = Event::factory()->registrationOpen()->create([
            'registration_open_at' => CarbonImmutable::now()->addDay(),
        ]);

        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$future->slug}/registrations", registrationPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.details.reason', 'registration_window_not_open');
    });
});

describe('POST /api/v1/events/{slug}/registrations — parceiro', function (): void {

    it('recusa dupla consigo mesmo', function (): void {
        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload([
                'partner_mode' => 'PARTNER',
                'partner_user_id' => $this->athlete->id,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'partner_is_self');
    });

    it('recusa parceiro já inscrito no evento', function (): void {
        $partner = User::factory()->create(['name' => 'Rafael Lima']);

        Registration::factory()->forEvent($this->event)->forUser($partner)->create();

        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload([
                'partner_mode' => 'PARTNER',
                'partner_user_id' => $partner->id,
            ]))
            ->assertStatus(409)
            ->assertJsonPath('error.details.reason', 'partner_already_registered');
    });

    it('recusa parceiro com conta bloqueada', function (): void {
        $blocked = User::factory()->create(['status' => UserStatus::BLOCKED]);

        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload([
                'partner_mode' => 'PARTNER',
                'partner_user_id' => $blocked->id,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'partner_blocked');
    });

    it('exige parceiro no modo PARTNER', function (): void {
        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload([
                'partner_mode' => 'PARTNER',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['partner_user_id']]]);
    });

    /*
     * Enviar parceiro num modo que não forma dupla é pedido incoerente. Aceitar
     * em silêncio criaria uma dupla que a tela não pediu.
     */
    it('proíbe parceiro nos modos que não formam dupla', function (string $mode): void {
        $partner = User::factory()->create();

        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload([
                'partner_mode' => $mode,
                'partner_user_id' => $partner->id,
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['partner_user_id']]]);
    })->with(['INDIVIDUAL', 'SEEKING']);
});

describe('POST /api/v1/events/{slug}/registrations — validação e acesso', function (): void {

    it('nega anônimo com 401', function (): void {
        $this->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload())
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    });

    it('exige aceite do regulamento', function (): void {
        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload([
                'accept_rules' => false,
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['accept_rules']]]);
    });

    it('recusa modo de inscrição inexistente', function (): void {
        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload([
                'partner_mode' => 'TRIO',
            ]))
            ->assertStatus(422);
    });

    it('devolve 404 para evento inexistente', function (): void {
        $this->actingAs($this->athlete)
            ->postJson('/api/v1/events/evento-que-nao-existe/registrations', registrationPayload())
            ->assertNotFound();
    });

    it('grava a versão do regulamento aceita (LGPD §12)', function (): void {
        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload())
            ->assertCreated();

        expect(Registration::query()->sole()->accepted_rules_version)
            ->toBe((string) config('saque.terms_version'));
    });
});

describe('POST /api/v1/events/{slug}/registrations — auditoria', function (): void {

    it('grava REGISTRATION_CREATED com o contexto da inscrição', function (): void {
        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload())
            ->assertCreated();

        $log = AuditLog::query()
            ->where('action', AuditAction::REGISTRATION_CREATED->value)
            ->sole();

        expect($log->actor_id)->toBe($this->athlete->id)
            ->and($log->target_type)->toBe('registration')
            ->and($log->metadata['event_slug'])->toBe($this->event->slug)
            ->and($log->metadata['partner_mode'])->toBe('INDIVIDUAL');
    });

    /*
     * Regressão: `AuditLogger` lia `roleList()[0]` sem guarda e estourava
     * "Undefined array key 0" para ator sem papel. Como o logger engole a
     * exceção por design (falha de auditoria não derruba negócio), o efeito era
     * a trilha desaparecer sem ninguém notar.
     */
    it('audita mesmo quando o ator não tem papel algum', function (): void {
        $roleless = User::factory()->create();

        $this->actingAs($roleless)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", registrationPayload())
            ->assertCreated();

        $log = AuditLog::query()
            ->where('action', AuditAction::REGISTRATION_CREATED->value)
            ->sole();

        expect($log->actor_id)->toBe($roleless->id)
            ->and($log->actor_role)->toBeNull();
    });
});

describe('GET /api/v1/me/registrations', function (): void {

    it('lista apenas as inscrições do próprio atleta', function (): void {
        Registration::factory()->count(2)->forEvent($this->event)->forUser($this->athlete)->cancelled()->create();
        Registration::factory()->forEvent($this->event)->create();   // de outra pessoa

        $this->actingAs($this->athlete)
            ->getJson('/api/v1/me/registrations')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('nega anônimo com 401', function (): void {
        $this->getJson('/api/v1/me/registrations')->assertUnauthorized();
    });

    /*
     * CLAUDE.md §6 proíbe N+1. Com `preventLazyLoading` ativo em teste, uma
     * relação esquecida derruba a requisição — mas a contagem garante que o
     * eager loading não degradou para uma query por linha.
     */
    it('não faz N+1 na listagem', function (): void {
        Registration::factory()->count(5)->forEvent($this->event)->forUser($this->athlete)->cancelled()->create();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAs($this->athlete)->getJson('/api/v1/me/registrations')->assertOk();

        // Uma contagem de paginação, uma da lista, e as relações agregadas.
        expect($queries)->toBeLessThan(12);
    });
});
