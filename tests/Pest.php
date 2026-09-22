<?php

declare(strict_types=1);

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Feature tests rodam contra PostgreSQL real (ver phpunit.xml).
 *
 * Isso é deliberado: boa parte das garantias do sistema é do banco —
 * unique constraints, check constraints e os triggers append-only de
 * audit_logs. Testar contra SQLite validaria um sistema que não é o nosso.
 *
 * Unit tests de domínio NÃO tocam o banco (CLAUDE.md §22): cálculo financeiro
 * tem de ser testável sem infraestrutura.
 */
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/*
 * O Sanctum em modo cookie (ADR 0005) só trata a requisição como stateful —
 * e portanto só abre sessão — quando ela vem de uma origem da allowlist.
 * Um SPA real sempre envia Origin; o cliente de teste não envia por padrão.
 * Sem isto, toda rota de sessão falha com "Session store not set on request".
 */
pest()->beforeEach(function (): void {
    $this->withHeader('Origin', (string) config('app.url'));
})->in('Feature');

/**
 * Dupla `COMPLETE`, com os dois membros confirmados (ADR 0001). Compartilhado
 * entre os testes de `tests/Feature/Http/Brackets` — é o critério de "dupla
 * apta ao sorteio" (ADR 0011), o mesmo que `StartDrawAction` usa.
 */
function confirmedTeam(Event $event): RegistrationGroup
{
    $group = RegistrationGroup::factory()->forEvent($event)->complete()->create();

    Registration::factory()->forEvent($event)->confirmed()->inGroup($group, captain: true)->create();
    Registration::factory()->forEvent($event)->confirmed()->inGroup($group)->create();

    return $group;
}
