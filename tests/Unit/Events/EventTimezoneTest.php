<?php

declare(strict_types=1);

use App\Modules\Events\Domain\EventTimezone;

/*
 * ADR 0006 exige explicitamente este teste:
 *
 *   "evento criado com date=2026-08-22, start=08:30 persiste
 *    2026-08-22T11:30:00Z e volta pela API como 2026-08-22T08:30:00-03:00"
 *
 * É o teste que impede a regressão mais cara do módulo: gravar hora de parede
 * como se fosse UTC. O sintoma seria um campeonato começando três horas antes
 * na tela de todo mundo.
 */

it('converte hora de parede do evento para UTC', function () {
    $utc = EventTimezone::default()->toUtc('2026-08-22', '08:30');

    expect($utc->toIso8601String())->toBe('2026-08-22T11:30:00+00:00');
});

it('volta ao fuso do evento com o offset correto', function () {
    $tz = EventTimezone::default();

    expect($tz->toUtc('2026-08-22', '08:30')->setTimezone($tz->name)->toIso8601String())
        ->toBe('2026-08-22T08:30:00-03:00');
});

it('aceita hora com segundos, como alguns navegadores enviam', function () {
    expect(EventTimezone::default()->toUtc('2026-08-22', '08:30:00')->toIso8601String())
        ->toBe('2026-08-22T11:30:00+00:00');
});

it('mantém a data quando a conversão cruza a meia-noite', function () {
    // 23h59 em São Paulo é 02h59 do dia seguinte em UTC.
    expect(EventTimezone::default()->toUtc('2026-08-18', '23:59')->toIso8601String())
        ->toBe('2026-08-19T02:59:00+00:00');
});

it('usa uma constante única — a decisão do ADR não se espalha', function () {
    expect(EventTimezone::DEFAULT)->toBe('America/Sao_Paulo')
        ->and(EventTimezone::default()->name)->toBe(EventTimezone::DEFAULT);
});
