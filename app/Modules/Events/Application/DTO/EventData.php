<?php

declare(strict_types=1);

namespace App\Modules\Events\Application\DTO;

use App\Modules\Events\Domain\Enums\AgeCategory;
use App\Modules\Events\Domain\Enums\EventType;
use App\Modules\Events\Domain\Enums\GenderCategory;
use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Events\Domain\Enums\Modality;
use App\Modules\Events\Domain\Enums\RegistrationFieldKey;
use App\Modules\Events\Domain\EventTimezone;

/**
 * Dados de um evento, já validados na fronteira HTTP.
 *
 * As datas viajam como **hora de parede** (exatamente o que o organizador
 * digitou nos inputs `type="date"` e `type="time"`), acompanhadas do fuso.
 * A conversão para UTC acontece uma única vez, dentro da action (ADR 0006 §4) —
 * é por isso que o DTO não carrega CarbonImmutable já convertido: converter no
 * FormRequest espalharia a decisão de fuso por outra camada.
 */
final readonly class EventData
{
    /**
     * @param  string  $date  data ISO — "2026-08-22"
     * @param  string  $startTime  hora de parede — "08:30"
     * @param  string  $endTime  hora de parede — "18:00"
     * @param  string|null  $registrationOpenAt  "2026-08-01 09:00" na hora local do evento
     * @param  string|null  $registrationCloseAt  "2026-08-18 23:59" na hora local do evento
     * @param  int|null  $maxTeams  null = sem teto de duplas
     * @param  array<int, string>  $rules
     * @param  list<RegistrationFieldKey>|null  $requiredRegistrationFields  Q19, ADR 0016 — null = não enviado, preserva em edição
     * @param  list<RegistrationFieldKey>|null  $optionalRegistrationFields
     * @param  array{win: int, loss: int, champion: int, runnerUp: int, third: int, participation: int}|null  $scoringRules  ADR 0008 — null = mantém/usa o default da constante da plataforma
     *
     * Os cinco campos operacionais seguintes são `?int` (não `int` com default) de
     * propósito: `null` significa "não enviado nesta requisição". Em criação, a action
     * aplica o default; em edição, `null` preserva o valor já gravado — sem isso, um
     * PATCH que omitisse `days_count` reescreveria o evento de volta para 1 dia.
     */
    public function __construct(
        public string $name,
        public string $venueName,
        public string $city,
        public string $state,
        public string $date,
        public string $startTime,
        public string $endTime,
        public int $registrationFeeCents,
        public ?int $maxTeams,
        public int $courts,
        public int $minGames,
        public Modality $modality,
        public GenderCategory $genderCategory,
        public LevelCategory $levelCategory,
        public AgeCategory $ageCategory,
        public EventType $eventType,
        public EventTimezone $timezone,
        public ?string $description = null,
        public ?string $registrationOpenAt = null,
        public ?string $registrationCloseAt = null,
        public ?string $format = null,
        public ?string $prizeDescription = null,
        public array $rules = [],
        public ?array $requiredRegistrationFields = null,
        public ?array $optionalRegistrationFields = null,
        public ?int $daysCount = null,
        public ?int $matchDurationMin = null,
        public ?int $bestOfSets = null,
        public ?int $pointsPerSet = null,
        public ?int $tiebreakPoints = null,
        public ?array $scoringRules = null,
    ) {}
}
