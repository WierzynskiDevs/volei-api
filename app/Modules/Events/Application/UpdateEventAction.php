<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Events\Application\DTO\EventData;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Domain\Enums\RegistrationFieldKey;
use App\Modules\Events\Domain\Exceptions\EventNotEditableException;
use App\Modules\Events\Domain\Exceptions\JustificationRequiredException;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Edição de evento (BRIEF §35/§36).
 *
 * Duas regras que esta action carrega:
 *
 *  1. Evento em estado terminal não é editado — reescrever um evento finalizado
 *     altera o histórico de quem participou.
 *
 *  2. Alterar **data, horário ou local** de um evento já publicado é uma
 *     mudança material: gera direito a reembolso para quem já se inscreveu.
 *     Por isso exige justificativa registrada. Enquanto o evento é rascunho,
 *     ninguém foi afetado e a exigência não faz sentido.
 *
 * O que esta action NÃO faz: disparar reembolso ou notificar inscritos. Isso é
 * dos módulos Registrations e Payments; aqui fica a decisão de domínio e o
 * rastro de auditoria que aqueles módulos vão consumir.
 */
final readonly class UpdateEventAction
{
    /** Campos cuja alteração afeta quem já se inscreveu. */
    private const array MATERIAL_FIELDS = [
        'start_at',
        'end_at',
        'venue_name',
        'city',
        'state',
        'registration_fee_cents',
    ];

    public function __construct(
        private EventSlugGenerator $slugs,
        private AuditLogger $audit,
    ) {}

    public function execute(
        Event $event,
        User $actor,
        EventData $data,
        ?string $justification = null,
    ): Event {
        if (! $event->status->isEditable()) {
            throw EventNotEditableException::from($event->status);
        }

        $attributes = $this->attributesFrom($data);

        // O slug só é recalculado quando o nome muda, e nunca depois de
        // publicado: o link já foi compartilhado e quebrá-lo é quebrar
        // a promessa feita a quem recebeu o link.
        if ($event->status === EventStatus::DRAFT && $data->name !== $event->name) {
            $attributes['slug'] = $this->slugs->uniqueFor($data->name, ignoreEventId: $event->id);
        }

        $changes = $this->materialChanges($event, $attributes);

        if ($changes !== [] && $event->status !== EventStatus::DRAFT
            && ($justification === null || trim($justification) === '')) {
            throw JustificationRequiredException::forFields(array_keys($changes));
        }

        DB::transaction(function () use ($event, $attributes): void {
            $event->fill($attributes);
            $event->save();
        });

        $this->audit->log(
            action: AuditAction::EVENT_UPDATED,
            actor: $actor,
            targetType: 'event',
            targetId: $event->id,
            metadata: array_filter([
                'material_changes' => $changes === [] ? null : $changes,
                'justification' => $justification,
            ], fn (mixed $v): bool => $v !== null),
        );

        return $event;
    }

    /**
     * Diferença entre o que está gravado e o que se pretende gravar, restrita
     * aos campos materiais. Devolve o par antes/depois para a auditoria — é
     * essa evidência que sustenta uma disputa de reembolso.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{from: string, to: string}>
     */
    private function materialChanges(Event $event, array $attributes): array
    {
        $changes = [];

        foreach (self::MATERIAL_FIELDS as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $before = $this->stringify($event->getAttribute($field));
            $after = $this->stringify($attributes[$field]);

            if ($before !== $after) {
                $changes[$field] = ['from' => $before, 'to' => $after];
            }
        }

        return $changes;
    }

    private function stringify(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return (string) (is_scalar($value) ? $value : json_encode($value));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFrom(EventData $data): array
    {
        $tz = $data->timezone;

        return [
            'name' => $data->name,
            'description' => $data->description,
            'venue_name' => $data->venueName,
            'city' => $data->city,
            'state' => $data->state,
            'timezone' => $tz->name,
            'start_at' => $tz->toUtc($data->date, $data->startTime),
            'end_at' => $tz->toUtc($data->date, $data->endTime),
            'registration_open_at' => $this->windowToUtc($data, $data->registrationOpenAt),
            'registration_close_at' => $this->windowToUtc($data, $data->registrationCloseAt),
            'registration_fee_cents' => $data->registrationFeeCents,
            'max_teams' => $data->maxTeams,
            'courts' => $data->courts,
            'min_games' => $data->minGames,
            'format' => $data->format,
            'modality' => $data->modality,
            'gender_category' => $data->genderCategory,
            'level_category' => $data->levelCategory,
            'age_category' => $data->ageCategory,
            'event_type' => $data->eventType,
            'prize_description' => $data->prizeDescription,
            'rules' => $data->rules,
            ...($data->requiredRegistrationFields !== null
                ? ['required_registration_fields' => $this->fieldValues($data->requiredRegistrationFields)]
                : []),
            ...($data->optionalRegistrationFields !== null
                ? ['optional_registration_fields' => $this->fieldValues($data->optionalRegistrationFields)]
                : []),
            // Edição: campo omitido PRESERVA o valor já gravado — nunca volta
            // para o default. `$event->fill()` só toca o que está aqui dentro.
            ...($data->daysCount !== null ? ['days_count' => $data->daysCount] : []),
            ...($data->matchDurationMin !== null ? ['match_duration_min' => $data->matchDurationMin] : []),
            ...($data->bestOfSets !== null ? ['best_of_sets' => $data->bestOfSets] : []),
            ...($data->pointsPerSet !== null ? ['points_per_set' => $data->pointsPerSet] : []),
            ...($data->tiebreakPoints !== null ? ['tiebreak_points' => $data->tiebreakPoints] : []),
            ...($data->scoringRules !== null ? ['scoring_rules' => $data->scoringRules] : []),
        ];
    }

    /**
     * @param  list<RegistrationFieldKey>  $fields
     * @return list<string>
     */
    private function fieldValues(array $fields): array
    {
        return array_map(fn (RegistrationFieldKey $f): string => $f->value, $fields);
    }

    private function windowToUtc(EventData $data, ?string $localDateTime): ?string
    {
        if ($localDateTime === null) {
            return null;
        }

        [$date, $time] = explode(' ', $localDateTime, 2);

        return $data->timezone->toUtc($date, $time)->toIso8601String();
    }
}
