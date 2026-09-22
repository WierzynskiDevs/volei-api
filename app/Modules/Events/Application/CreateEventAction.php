<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Events\Application\DTO\EventData;
use App\Modules\Events\Domain\Enums\EventStatus;
use App\Modules\Events\Domain\Enums\RegistrationFieldKey;
use App\Modules\Events\Domain\Exceptions\OrganizerCannotOperateException;
use App\Modules\Events\Domain\Exceptions\PlanEventLimitReachedException;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Criação de evento (BRIEF §17).
 *
 * O evento nasce SEMPRE como rascunho. Publicar é uma transição separada e
 * explícita (`PublishEventAction`): é a publicação que expõe o link ao público
 * e habilita cobrança, e ela tem pré-condições próprias.
 *
 * Invariantes garantidas aqui:
 *  - organizador bloqueado não cria evento (BRIEF §35);
 *  - o limite de eventos do plano é respeitado, lido da tabela `plans` e nunca
 *    hardcoded (CLAUDE.md §7.8);
 *  - a hora de parede vira UTC uma única vez, com o fuso do evento (ADR 0006);
 *  - o slug é único entre os eventos vivos, resolvido no servidor — o cliente
 *    não escolhe endereço público.
 */
final readonly class CreateEventAction
{
    public function __construct(
        private EventSlugGenerator $slugs,
        private AuditLogger $audit,
    ) {}

    public function execute(Organizer $organizer, User $actor, EventData $data): Event
    {
        if (! $organizer->status->canOperate()) {
            throw OrganizerCannotOperateException::create();
        }

        $this->assertPlanAllowsAnotherEvent($organizer);

        $event = DB::transaction(function () use ($organizer, $actor, $data): Event {
            $event = new Event;

            $event->fill($this->attributesFrom($data));
            $event->organizer_id = $organizer->id;
            $event->slug = $this->slugs->uniqueFor($data->name);
            $event->status = EventStatus::DRAFT;
            $event->created_by = $actor->id;

            $event->save();

            /*
             * `teams_registered_count` e `status` têm DEFAULT no banco. Sem o
             * refresh, o model recém-salvo devolve null para eles e o Resource
             * publica `"teams_registered_count": null` — a tela leria "null/16
             * duplas".
             */
            $event->refresh();

            return $event;
        });

        $this->audit->log(
            action: AuditAction::EVENT_CREATED,
            actor: $actor,
            targetType: 'event',
            targetId: $event->id,
            metadata: [
                'organizer_id' => $organizer->id,
                'slug' => $event->slug,
                'registration_fee_cents' => $event->registration_fee_cents,
                'start_at' => $event->start_at->toIso8601String(),
            ],
        );

        return $event;
    }

    /**
     * Limite de eventos do plano.
     *
     * Cancelado não conta: punir o organizador por ter cancelado empurraria a
     * decisão errada — manter de pé um evento que não vai acontecer.
     * `event_limit` NULL significa ilimitado (plano PREMIUM).
     */
    private function assertPlanAllowsAnotherEvent(Organizer $organizer): void
    {
        $plan = $organizer->plan;

        if ($plan === null || $plan->hasUnlimitedEvents()) {
            return;
        }

        $active = Event::query()
            ->where('organizer_id', $organizer->id)
            ->where('status', '!=', EventStatus::CANCELLED->value)
            ->count();

        if ($active >= $plan->event_limit) {
            throw PlanEventLimitReachedException::forPlan($plan->code, (int) $plan->event_limit);
        }
    }

    /**
     * Conversão do DTO para colunas. As três chamadas a `toUtc` são o único
     * ponto do sistema que interpreta hora de parede (ADR 0006).
     *
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
            'registration_open_at' => $this->toUtcOrNull($data->registrationOpenAt, $data),
            'registration_close_at' => $this->toUtcOrNull($data->registrationCloseAt, $data),
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
            // Criação: campo omitido usa o default da coluna (CLAUDE.md §6),
            // não um valor duplicado aqui — se o default da migration mudar,
            // só muda num lugar.
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

    private function toUtcOrNull(?string $localDateTime, EventData $data): ?string
    {
        if ($localDateTime === null) {
            return null;
        }

        [$date, $time] = explode(' ', $localDateTime, 2);

        return $data->timezone->toUtc($date, $time)->toIso8601String();
    }
}
